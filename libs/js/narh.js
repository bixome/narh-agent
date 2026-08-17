/* ==========================================================================
   NARH — la porte unique côté navigateur.

   Une seule surface : la conversation. Ce module ne dessine rien (CLAUDE.md,
   règle 2) — il demande au serveur du HTML déjà rendu par `src/Vue.php` et le
   pose. Seul le flux de jetons s'écrit ici, parce qu'un jeton n'a pas de forme.

   Il n'ajoute aucun comportement que XOSHUI porte déjà : sélection, menu
   contextuel, palette, modales, notifications viennent du framework. On lui
   redonne seulement la main (`mount`) sur les fragments insérés.

   Trois portes mènent à `commander()` — le champ (`/veille`), la palette et le
   clic droit. Rien n'est branché ailleurs (règle 5).
   ========================================================================== */

/* Le framework est importé avec la version que porte notre propre URL, et non
   par un `./xoshui.js` nu.

   Le <script> de la page charge « xoshui.js?v=… » ; pour le navigateur, une URL
   différente est un module différent. L'importer sans sa version en chargeait
   donc une seconde instance, avec son propre registre de montage : chaque
   liste, chaque menu et chaque notification se retrouvaient avec deux jeux de
   gestionnaires. Mesuré avant correction : un seul Entrée dans la palette
   déclenchait deux fois la commande. */
const { mount } = await import('./xoshui.js' + new URL(import.meta.url).search);

const app = document.querySelector('.xo-app');
const phase = app?.dataset.phase ?? '';

/* Les commandes déclarées mais pas encore branchées, et la phase qui les
   amènera — telles que `Ecran::COMMANDES` les décide. Sans cette table, le
   message de repli annonçait la phase de l'**application** à leur place : une
   commande déclarant P4 s'annonçait « après P5 », donc après elle-même. */
const phases = (() => {
  try {
    return JSON.parse(app?.dataset.phases || '{}');
  } catch {
    return {};
  }
})();
const saisie = document.getElementById('chat-saisie');

/* --- Menus --------------------------------------------------------------- */

function texte(id, valeur) {
  const cible = document.getElementById(id);
  if (cible) cible.textContent = String(valeur);
}

/**
 * Reposer un fragment sans déplacer ce qu'on regardait.
 *
 * Deux défilements sont en jeu et tous deux se perdaient :
 *
 * - **celui du conteneur** : remplacer son contenu le remet à zéro, et une
 *   liste de veille qu'on venait de parcourir repartait du haut à chaque
 *   rafraîchissement ;
 * - **celui de l'ancêtre** : `mount()` fait sélectionner à XOSHUI la première
 *   ligne de toute liste insérée, et cette sélection appelle `scrollIntoView`,
 *   qui remonte au passage la zone défilante qui l'entoure.
 *
 * On note les deux avant, on les rend après. Le contenu change, la vue non.
 */
function remplir(id, html) {
  const cible = document.getElementById(id);
  if (!cible || html === undefined) return;

  const defilant = cible.closest('.xo-scroll') ?? cible;
  const sien = cible.scrollTop;
  const celuiDuParent = defilant.scrollTop;

  cible.innerHTML = html;
  mount(cible);            // les listes insérées redeviennent navigables
  // …et n'emportent pas une sélection avec elles (voir selectionUnique).
  selectionUnique(selection());   // le choix de l’utilisateur, pas la dernière liste montée

  cible.scrollTop = sien;
  defilant.scrollTop = celuiDuParent;
}

function duree(ms) {
  return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1).replace('.', ',')}s`;
}

/**
 * La petite zone d'attente des gestes courts.
 *
 * Un clic qui part vers le serveur sans rien changer à l'écran passe pour un
 * clic perdu — et on reclique. Une ligne discrète sous le champ suffit à dire
 * que c'est parti, sans bloquer quoi que ce soit.
 *
 * `null` la referme.
 */
/* --- Le compteur d'outils -------------------------------------------------
   « Outils N » n'était pas un état mais un nombre, et il ne se rafraîchissait
   qu'en fin de réponse — jamais pendant qu'un outil tournait. Pire, `compte`
   n'était pas rendu par `api/fils.php` : l'écran affichait « undefined » après
   chaque réponse.

   Il porte maintenant l'état, là où l'on va déjà chercher le détail. Trois
   tons, et pas un de plus — la couleur en porte déjà quatre dans cet écran
   (marquage, fraîcheur, confirmation, sélection), lui en inventer une
   cinquième était le vrai risque du bandeau qu'on n'a pas fait.

   Le compte est tenu depuis les événements du flux, qui arrivent déjà, puis
   réconcilié avec le serveur en fin de réponse. Pas de seconde voie de
   sondage : `api.php` n'a pas de session, et lui en donner une ferait entrer
   le sondage en contention avec le flux SSE — PHP verrouille le fichier de
   session le temps d'une requête. */

const outilsEtat = { compte: 0, echecs: 0, enCours: 0 };

function majOutils({ compte, echecs, enCours } = {}) {
  if (compte !== undefined) outilsEtat.compte = Number(compte) || 0;
  if (echecs !== undefined) outilsEtat.echecs = Number(echecs) || 0;
  if (enCours !== undefined) outilsEtat.enCours = Math.max(0, enCours);

  const cible = document.getElementById('desk-outils-compte');
  if (!cible) return;

  /* Ce qui tourne prime sur ce qui a raté : pendant un appel, ce qu'on veut
     savoir est qu'il faut attendre. L'échec se relira juste après. */
  const [texteCompte, ton] = outilsEtat.enCours > 0
    ? [`${outilsEtat.compte + outilsEtat.enCours}…`, 'xo-accent']
    : outilsEtat.echecs > 0
      ? [`${outilsEtat.compte} · ${outilsEtat.echecs} en échec`, 'xo-danger']
      : [String(outilsEtat.compte), 'xo-muted'];

  cible.textContent = texteCompte;
  cible.className = ton;
}

/* Les gestes en vol.
   Un aller-retour serveur n'est pas instantané, et une commande relancée avant
   sa réponse en fait deux : deux fils neufs, deux oublis. Le spinner le dit,
   mais dire ne suffit pas — on refuse aussi. Une clé par famille de gestes, pas
   une par commande : ouvrir un fil et en oublier un touchent le même objet. */
const enVol = new Set();

function occupe(dit) {
  const zone = document.getElementById('chat-phase');
  if (!zone) return;

  zone.hidden = dit === null;
  if (dit !== null) zone.querySelector('[data-dit]').textContent = dit;
}

/* --- Notifications -------------------------------------------------------
   Le balisage vient du gabarit rendu par PHP : on le clone et on remplit deux
   textes. C'est la règle 2 tenue jusqu'au bout. */

const GLYPHES_TOAST = { success: '✓', warning: '▲', danger: '✗', info: 'i' };

function notifier(ton, titre, detail = '', delai = 6000) {
  const gabarit = document.getElementById('gabarit-toast');
  const bac = document.getElementById('toasts');
  if (!gabarit || !bac) return;

  const noeud = gabarit.content.cloneNode(true);
  const toast = noeud.querySelector('.xo-toast');

  if (ton !== 'info') toast.classList.add(`xo-toast--${ton}`);
  toast.dataset.xoToast = String(delai);
  toast.querySelector('[aria-hidden]').textContent = GLYPHES_TOAST[ton] ?? '·';
  toast.querySelector('.xo-toast__title').textContent = `${titre}.`;
  toast.querySelector('.xo-toast__body').append(detail);

  bac.prepend(noeud);
  mount(bac);              // XOSHUI câble la fermeture et le délai
}

/** La pulsation de confirmation, retirée dès qu'elle a fini de battre. */
function confirmer(el, ton = 'success') {
  if (!el) return;
  const classe = `xo-flash--${ton}`;
  el.classList.add(classe);
  el.addEventListener('animationend', () => el.classList.remove(classe), { once: true });
}

/* --- La porte unique -----------------------------------------------------
   Une action, d'où qu'elle vienne. Les trois portes disent la même chose ;
   elles passent donc par la même fonction, sinon elles divergeraient au
   premier ajout d'entrée. */

/**
 * La ligne que l'utilisateur a désignée — et elle seule.
 *
 * Retenue explicitement, pas retrouvée dans le DOM : chaque segment d'antenne
 * monte une liste, XOSHUI y sélectionne la première ligne, et « la première
 * sélection trouvée » désignait alors la dernière arrivée. Le curseur sautait
 * tout seul toutes les onze secondes.
 *
 * `isConnected` : une ligne dont la liste a été reposée n'existe plus, même si
 * la variable la référence encore.
 */
let choisie = null;

function selection(item = null) {
  if (item) return item;

  return choisie?.isConnected ? choisie : null;
}

/**
 * Une seule ligne sélectionnée sur toute la surface.
 *
 * XOSHUI sélectionne la première ligne de **chaque** liste à son montage — le
 * comportement est juste pour une liste isolée, mais l'écran en compte une
 * dizaine : les alertes, chaque tuile de veille, les sources sous une réponse,
 * chaque segment d'antenne. Résultat, autant de barres en vidéo inverse, et une
 * sélection qui ne veut plus rien dire alors que les gestes de desk agissent
 * dessus.
 *
 * On ne touche pas au framework : on rétablit l'invariant après chaque montage.
 * La palette est épargnée — sa sélection est celle du clavier, à l'intérieur
 * d'une boîte, et elle a un sens propre.
 */
function selectionUnique(sauf = null) {
  for (const el of document.querySelectorAll('.xo-main [role="option"][aria-selected="true"]')) {
    if (el !== sauf) el.setAttribute('aria-selected', 'false');
  }
}

/* `argument` : ce que le champ a tapé après le nom de la commande. Seules
   celles qui cherchent en ont besoin — `/corpus incendie Landes`. Les autres
   portes (palette, menu, bouton) n'en fournissent pas, et n'ont pas à le
   faire : une commande qui exige un argument doit le dire quand il manque,
   pas être absente des portes qui ne savent pas en donner. */
async function commander(action, item = null, porte = 'inconnue', argument = '') {
  if (!action) return;

  const cible = selection(item);

  switch (action) {
    /* -- Le régime -- */
    case 'direct':
      await basculerAntenne(true);
      return;

    case 'conversation':
      await basculerAntenne(false);
      return;

    /* -- Convoquer une tuile -- */
    case 'veille':
    case 'alertes':
    case 'journal':
    case 'memoire':
      await poserTuile(action);
      return;

    /* Le corpus se comporte pareil depuis les quatre portes.
       Il a d'abord refusé de s'ouvrir sans requête, ce qui en faisait une
       commande à deux vitesses : utilisable depuis le champ, inerte depuis la
       palette et le clic droit, qui ne savent pas donner d'argument. C'est
       exactement ce que la règle 5 cherche à éviter. Sans mots, il montre donc
       ce qu'il a de plus récent — et le champ reste là pour affiner. */
    case 'corpus':
      await poserTuile('corpus', (argument ?? '').trim() ? { q: argument.trim() } : {});
      return;

    /* Lire, c'est afficher le texte ici ; ouvrir, c'est aller sur le site.
       Les deux existent parce qu'ils ne servent pas au même moment — et lire
       ne fait aucune requête depuis le navigateur. */
    case 'lire': {
      const id = cible?.dataset.value;
      if (!id) {
        notifier('info', 'Aucune ligne visée', "Choisir d'abord une dépêche.", 3000);
        return;
      }
      await poserTuile('lecture', { id });
      return;
    }

    case 'inspecter': {
      /* Le détail va dans son onglet, pas dans le flux : inspecter est un coup
         d'œil, et en garder une trace datée à chaque fois remplirait la
         conversation de ce qu'on a seulement regardé. */
      const id = cible?.dataset.value;
      if (!id) {
        notifier('info', 'Aucune ligne visée', "Choisir d'abord une ligne.", 3000);
        return;
      }
      await montrerInspecte(id);
      return;
    }

    /* -- Agir sur une ligne -- */
    case 'ouvrir': {
      const lien = cible?.dataset.lien
        ?? document.querySelector(`[data-parent="${cible?.dataset.groupe}"]`)?.dataset.lien;
      if (!lien) {
        notifier('info', 'Pas de lien', "Déplier l'événement pour ouvrir une dépêche.", 4000);
        return;
      }
      /* Un onglet bloqué ne dit rien de lui-même : c'était la seule action de
         l'écran dont l'échec était invisible, et on croyait au lien mort.
         `window.open` rend null quand le navigateur refuse — on le dit, et on
         propose la lecture locale, qui ne dépend d'aucune permission. */
      if (window.open(lien, '_blank', 'noopener') === null) {
        notifier('warning', 'Onglet bloqué',
          'Le navigateur a refusé la fenêtre. « Lire le texte ici » ne demande rien.', 6000);
      }
      return;
    }

    case 'suivi':
    case 'traite':
    case 'ecarte':
      await marquer(action, cible);
      return;

    case 'interroger': {
      /* Le pont, sens veille → agent : le champ porte la dépêche sans encore
         l'écrire dans le fil — c'est le premier message qui la versera.

         Sans rechargement, comme `fil-neuf`. Il rechargeait la page jusqu'ici,
         ce que le commentaire de `fil-neuf` proscrit trois lignes plus bas :
         l'écran clignotait entier pour un bandeau, et depuis que la zone
         d'inspection s'ouvre au clic, le rechargement la refermait — on perdait
         ce qu'on regardait au moment précis où l'on demande à en parler.

         Le bandeau vient du serveur déjà rendu (`Vue::ancre`), le navigateur ne
         fait que le poser (règle 2). L'adresse suit par `replaceState`, comme
         `desancrer` : rouvrir l'onglet retrouve la même ancre. */
      const id = cible?.dataset.value;
      if (!id) {
        notifier('info', 'Aucune ligne visée', "Choisir d'abord une dépêche.", 3000);
        return;
      }

      try {
        const data = await fetch(`api/apercu.php?type=ancre&id=${encodeURIComponent(id)}`)
          .then((r) => r.json());
        if (!data.ok) throw new Error(data.erreur || 'dépêche introuvable');

        document.getElementById('bandeau-ancre')?.remove();
        document.getElementById('zone-champ')?.insertAdjacentHTML('afterbegin', data.html);
        mount(document.getElementById('zone-champ'));

        if (saisie) {
          saisie.dataset.depeche = id;
          saisie.placeholder = 'Demandez à propos de cette dépêche…';
          saisie.focus();
        }

        const avec = new URL(location.href);
        avec.searchParams.set('depeche', id);
        history.replaceState(null, '', avec);
      } catch (erreur) {
        notifier('danger', 'Impossible de viser cette dépêche', String(erreur.message || erreur), 5000);
      }
      return;
    }

    /* Passer la main sans couper le direct : une prise de quart se relaie plus
       souvent qu'elle ne s'arrête. « Revenir en conversation » produit déjà une
       note, mais éteindre pour transmettre n'avait aucune raison d'être. */
    case 'quart': {
      if (!direct.antenne) {
        notifier('info', 'Antenne fermée', "La note de quart rend compte d'un direct — en ouvrir un d'abord.", 5000);
        return;
      }
      occupe('note de quart…');
      try {
        const data = await fetch('api/direct.php?action=quart').then((r) => r.json());
        if (!data.ok) throw new Error(data.erreur || 'note refusée');

        const suite = await appelerFils();
        if (suite?.tours !== undefined) poserTours(suite.tours);

        notifier('success', 'Note de quart versée au fil',
          `${data.bilan.segments} segments, ${data.bilan.sujets} sujets. L'antenne continue.`, 8000);
      } catch (erreur) {
        notifier('danger', 'Note impossible', String(erreur.message || erreur), 5000);
      } finally {
        occupe(null);
      }
      return;
    }

    /* -- La collecte et les fils -- */
    case 'relever':
      texte('etat-sondage', 'relevé en cours…');
      await sonder(true);
      return;

    case 'desancrer': {
      /* Quitter le mode dépêche sans rien perdre d'autre : le fil ouvert reste
         le même, seule la pièce qui allait être versée est retirée. Rien à
         demander au serveur — elle n'y a jamais été écrite, c'est le premier
         message qui l'aurait fait. */
      if (saisie) {
        saisie.dataset.depeche = '';
        saisie.placeholder = 'Demandez, ou tapez /veille…';
        saisie.focus();
      }
      document.getElementById('bandeau-ancre')?.remove();

      const propre = new URL(location.href);
      propre.searchParams.delete('depeche');
      history.replaceState(null, '', propre);

      notifier('info', 'Dépêche oubliée', 'La conversation reprend son cours ordinaire.', 4000);

      return;
    }

    /* Changer de fil ne recharge pas la page : seul le flux change, et le
       serveur le renvoie déjà rendu. Un rechargement coupait l'antenne le temps
       de tout refaire, et faisait clignoter l'écran entier pour une colonne. */
    case 'fil-neuf': {
      /* Mesuré : 352 ms côté serveur, et rien ne bougeait à l'écran pendant ce
         temps — assez pour croire au clic perdu et recommencer, ce qui ouvrait
         trois fils au lieu d'un. Le spinner du champ existait déjà et servait
         aux tuiles ; il manquait simplement ici. */
      if (enVol.has('fils')) return;
      enVol.add('fils');
      occupe('fil neuf…');
      try {
        const data = await appelerFils('neuf');
        if (data?.tours !== undefined) poserTours(data.tours);
        notifier('info', 'Fil neuf', 'Le précédent reste ouvrable par /memoire.', 4000);
      } finally {
        enVol.delete('fils');
        occupe(null);
      }
      return;
    }

    case 'oublier': {
      const id = cible?.dataset.value;
      if (!id) {
        notifier('info', 'Aucun fil visé', 'Choisir un fil dans une tuile.', 3000);
        return;
      }
      if (enVol.has('fils')) return;
      enVol.add('fils');
      occupe('oubli…');
      try {
        const data = await appelerFils('oublier', id);
        if (data?.tours !== undefined) poserTours(data.tours);
      } finally {
        enVol.delete('fils');
        occupe(null);
      }
      return;
    }

    default:
      /* Deux cas qu'il ne faut pas confondre, et qui se disaient pareil : une
         commande déclarée mais pas encore branchée, et un nom qui n'existe
         pas — le champ accepte n'importe quel `/mot`. Dans les deux cas l'écran
         parle plutôt que de ne rien faire : un bouton muet passe pour cassé. */
      console.info(`narh: ${action}`, { cible: cible?.dataset.value ?? null, porte });
      if (phases[action]) {
        notifier('warning', 'Pas encore branchée', `« ${action} » arrive avec ${phases[action]}.`, 5000);
      } else {
        notifier('info', 'Commande inconnue', `« ${action} » n'existe pas. Ctrl+K donne la liste.`, 5000);
      }
  }
}

/* Les trois portes. */

// 1. Le clic droit sur une ligne : XOSHUI ouvre le menu et émet xo:menu.
document.addEventListener('xo:menu', (e) => {
  commander(e.detail.action, e.detail.item, 'menu contextuel');
});

// 2. La palette, au clavier puis à la souris.
const palette = document.querySelector('[data-xo-palette]');

palette?.addEventListener('xo:activate', (e) => commander(e.detail.value, null, 'palette'));

/* XOSHUI n'émet `xo:activate` qu'au clavier : un clic sélectionne la ligne et
   referme la boîte, sans rien exécuter. L'écran se pilote aussi à la souris —
   le clic est donc branché ici. Les deux chemins ne se croisent pas : Entrée
   n'émet pas de clic, et un clic n'émet pas `xo:activate`. */
palette?.addEventListener('click', (e) => {
  const item = e.target.closest('.xo-list__item');
  if (item) commander(item.dataset.value, null, 'palette');
});

// 3. Les boutons qui portent une commande — en-tête compris.
document.addEventListener('click', (e) => {
  const bouton = e.target.closest('[data-action]');
  if (!bouton || bouton.closest('.xo-menu')) return;   // le menu a sa propre porte
  e.preventDefault();
  commander(bouton.dataset.action, null, 'bouton');
});

/* --- Les gestes de desk suivent la sélection -----------------------------
   La barre du Newsdesk agit sur la ligne choisie, où qu'elle ait été choisie —
   une alerte de l'en-tête, une dépêche d'une tuile. Elle reste grisée tant que
   rien n'est désigné : un bouton actif qui ne fait rien est pire qu'un bouton
   éteint. */

function majGestes() {
  const barre = document.getElementById('desk-gestes');
  if (!barre) return;

  const cible = selection();

  /* Montrer ou cacher, plutôt que griser : une barre présente mais inerte
     occupe la place et l'attention sans rien offrir. Absente, elle rend sa
     rangée à l'écran et son apparition dit à elle seule qu'une ligne est
     choisie — plus besoin d'écrire « aucune ligne ». */
  barre.hidden = cible === null;
  if (cible) texte('desk-cible', cible.dataset.libelle ?? '');
}

/* Choisir une ligne désélectionne toutes les autres, où qu'elles soient : la
   sélection est un état de la surface, pas de chaque liste. */
document.addEventListener('xo:select', (e) => {
  if (e.target.closest('.xo-palette')) return;
  choisie = e.detail.item;
  selectionUnique(choisie);
  majGestes();

  /* Cliquer une ligne l'inspecte : c'est le geste le plus fréquent, et le
     réclamer par une commande revenait à demander deux gestes pour un. Les
     fils, eux, se rouvrent — ils n'ont pas de détail à montrer. */
  const nature = choisie?.dataset.nature;
  if (nature === 'depeche' || nature === 'evenement') {
    montrerInspecte(choisie.dataset.value);

    /* La zone d'inspection s'ouvre ici, sur un choix réel, et non dans
       `majGestes()` sur l'état de la sélection : XOSHUI marque déjà une option
       comme choisie au montage de ses listes, si bien qu'au chargement une
       ligne est « sélectionnée » sans que personne l'ait désignée. La zone
       serait alors ouverte d'entrée, ce qui est précisément ce qu'on voulait
       éviter en la sortant du Newsdesk — elle pousserait la conversation vers
       le bas pour montrer une ligne qu'on n'a pas demandée.

       Elle ne se referme plus ensuite : une zone qui disparaît à chaque
       désélection ferait sauter le champ sous le curseur. */
    const zone = document.getElementById('inspection');
    if (zone) zone.hidden = false;
  }
});

document.addEventListener('click', majGestes);

/* --- Les tuiles ---------------------------------------------------------- */

/**
 * Poser une tuile dans la conversation.
 *
 * Le serveur l'inscrit comme un tour et renvoie la conversation entière, déjà
 * rendue : le navigateur remplace, il n'assemble pas.
 */
async function poserTuile(type, params = {}) {
  const url = new URLSearchParams({ type, ...params });
  occupe(`ouverture : ${type}…`);

  try {
    const reponse = await fetch(`api/tuile.php?${url}`);
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'tuile refusée');

    poserTours(data.tours);
  } catch (erreur) {
    notifier('danger', 'Tuile impossible', String(erreur.message || erreur), 5000);
  } finally {
    occupe(null);
  }
}

/**
 * Reposer la conversation dans le flux, sans emporter le direct.
 *
 * Les segments ne sont pas en base — ils n'existent que dans cette page. Un
 * remplacement naïf de la liste les effacerait à chaque question posée, et
 * l'antenne semblerait s'être interrompue. On les détache, on repose les tours
 * rendus par le serveur, puis on les remet en tête dans leur ordre.
 */
function poserTours(html) {
  if (!liste) return;

  const segments = [...liste.querySelectorAll('[data-segment]')];

  liste.innerHTML = html;

  /* Chacun **à sa place**, pas tous en tête : les remettre en bloc les faisait
     tous passer devant la réponse qu'on venait d'obtenir, alors qu'elle était
     plus récente. Le flux est une chronologie — un segment de 15:34 se lit
     après une réponse de 15:35. */
  for (const segment of segments) {
    inserer(segment);
  }

  mount(liste);
  selectionUnique(selection());   // le choix de l’utilisateur, pas la dernière liste montée
  document.getElementById('accueil')?.remove();

  // On repose le fil après une action : réponse, tuile, outil, note de quart.
  // Elle est en tête, et c'est là qu'on doit la trouver.
  defiler(true);
}

/** Poser un élément daté à sa place dans le flux, le plus récent en tête. */
function inserer(el) {
  if (!liste) return;

  const quand = Number(el.dataset.quand || 0);
  for (const ligne of liste.children) {
    if (Number(ligne.dataset.quand || 0) < quand) {
      liste.insertBefore(el, ligne);

      return;
    }
  }
  liste.append(el);
}

/**
 * Remonter en tête du flux.
 *
 * Deux régimes, et la règle est celle du regard :
 *
 * - **ce qu'on provoque** — une question, une tuile, un outil — remonte
 *   toujours (`force`). On vient d'agir, on doit voir le résultat, et le
 *   chercher en défilant serait absurde.
 * - **ce qui arrive tout seul** — un segment d'antenne — ne remonte que si on
 *   était déjà en tête. Au-delà de deux lignes de défilement, on lit autre
 *   chose : interrompre cette lecture toutes les onze secondes est exactement
 *   ce qu'on vient de corriger.
 */
function defiler(force = false) {
  const flux = document.getElementById('flux');
  if (flux && (force || flux.scrollTop < 40)) flux.scrollTop = 0;
}

/* --- Les fils ------------------------------------------------------------ */

async function appelerFils(action = 'etat', id = 0) {
  try {
    const reponse = await fetch(`api/fils.php?action=${action}&id=${id}`);
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'lecture impossible');

    // La jauge est rendue par le serveur : lui seul connaît les deux bouts —
    // les jetons relus en base, la fenêtre du modèle chez Ollama.
    remplir('jauge-contexte', data.contexte);

    /* Le poste de commande, ici et non chez les appelants : il ne se refaisait
       qu'après un lancement **manuel** d'outil, alors qu'une réponse du modèle
       en appelle plusieurs. Le panneau montrait donc l'avant-dernière question
       tant qu'on ne lançait rien à la main. `appelerFils()` suit chaque
       réponse, chaque geste sur les fils et le chargement : c'est le seul
       endroit qui les voit tous. */
    if (data.outils !== undefined) remplir('desk-outils', data.outils);
    majOutils({ compte: data.compte, echecs: data.echecs, enCours: 0 });

    /* « chargé » et « en ligne » sont deux choses : un modèle installé mais
       déchargé répond, en payant d'abord son chargement en mémoire. */
    const etatModele = document.getElementById('etat-modele');
    const m = data.moteur;
    const dit = !m.en_ligne ? 'hors ligne' : (m.charge ? m.modele : `${m.modele} · déchargé`);
    if (etatModele) {
      etatModele.textContent = dit;
      etatModele.className = m.en_ligne ? (m.charge ? 'xo-success' : 'xo-muted') : 'xo-warning';
    }

    return data;
  } catch (erreur) {
    notifier('danger', 'Fils illisibles', String(erreur.message || erreur), 5000);
    return null;
  }
}

/* Choisir un fil dans une tuile : XOSHUI émet xo:select, on ne fait qu'y
   répondre. Un fil se rouvre, il ne s'affiche pas en place — c'est un
   changement de conversation, pas un résultat de plus. */
document.addEventListener('xo:select', async (e) => {
  const item = e.detail.item;
  if (item?.dataset.nature !== 'fil') return;

  const data = await appelerFils('ouvrir', e.detail.value);
  if (data?.tours !== undefined) poserTours(data.tours);
});

/* --- Marquage de desk ----------------------------------------------------- */

/**
 * Marquer l'événement de la ligne désignée.
 *
 * Le serveur renvoie le balisage refait ; ici on recharge la conversation
 * entière plutôt que de recoudre une ligne dans une tuile : une tuile se refait
 * à l'affichage, et la recoudre à la main serait un second gabarit.
 */
async function marquer(valeur, item = null) {
  const cible = selection(item);
  if (!cible) {
    notifier('info', 'Rien de sélectionné', "Cliquer d'abord sur une ligne.", 3000);
    return;
  }

  const id = cible.dataset.value;
  // Rejouer la même action démarque : le geste est une bascule.
  const vise = cible.dataset.statut === valeur ? '' : valeur;

  occupe(vise === '' ? 'démarquage…' : `${vise}…`);

  try {
    const reponse = await fetch(
      `api.php?action=statut&id=${encodeURIComponent(id)}&valeur=${vise}&vue=arbre`
    );
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'marquage refusé');

    confirmer(cible, vise === '' ? 'warning' : 'success');
    cible.dataset.statut = vise;

    /* Le geste a une conséquence lisible : l'onglet du marquage et son compte
       se refont. Sans cela, on marquait sans jamais revoir ce qu'on avait
       marqué — et le Newsdesk mentait jusqu'au rechargement. */
    /* Le geste a une conséquence lisible : les trois listes et leurs comptes se
       refont. Toutes les trois, parce que marquer « traité » ce qui était
       « suivi » change deux onglets à la fois — n'en rafraîchir qu'un aurait
       laissé l'autre mentir jusqu'au prochain clic. */
    const etat = await fetch('api/fils.php').then((r) => r.json()).catch(() => null);
    if (etat?.ok) {
      majComptes(etat.statuts);
      for (const [cle, id] of Object.entries(ONGLETS)) remplir(id, etat[cle]);
    }
  } catch (erreur) {
    notifier('danger', 'Marquage impossible', String(erreur.message || erreur), 5000);
  } finally {
    occupe(null);
  }
}

/* Double-clic : ouvrir l'article. Le simple clic sélectionne déjà — les deux
   gestes ne peuvent pas être le même. */
document.addEventListener('dblclick', (e) => {
  const item = e.target.closest('[data-lien]');
  if (item?.dataset.lien) window.open(item.dataset.lien, '_blank', 'noopener');
});

/* Le chevron plie et déplie un événement dans une tuile de veille. Il est dans
   la ligne, donc XOSHUI la sélectionne au passage — c'est voulu : on plie ce
   qu'on vient de désigner. */
document.addEventListener('click', (e) => {
  const guide = e.target.closest('.xo-list__guide');
  const tete = guide?.closest('[data-pliable]');
  if (!tete) return;
  e.stopPropagation();

  const ouvert = !tete.hasAttribute('data-ouvert');
  tete.toggleAttribute('data-ouvert', ouvert);
  guide.textContent = ouvert ? '▾ ' : '▸ ';
  for (const enfant of tete.closest('.xo-list').querySelectorAll(`[data-parent="${tete.dataset.groupe}"]`)) {
    enfant.hidden = !ouvert;
  }
});

/* --- La conversation ------------------------------------------------------ */

const PHASES = {
  analyse: 'analyse…',
  reprise: 'reprise…',
  generation: 'génère…',
  outil: 'outil…',
  repos: '',
};

/**
 * Ouvrir un tour en attente, à la forme du tour à venir.
 *
 * Le gabarit vient de PHP : le navigateur le clone et remplit des textes. Il
 * porte `data-quand` pour se placer dans la chronologie comme n'importe quelle
 * autre ligne du flux.
 */
function ouvrirAttente() {
  const gabarit = document.getElementById('gabarit-attente');
  if (!gabarit || !liste) return null;

  const noeud = gabarit.content.cloneNode(true);
  const item = noeud.querySelector('[data-attente]');

  item.dataset.quand = Math.floor(Date.now() / 1000);
  item.querySelector('[data-heure]').textContent = new Date().toLocaleTimeString('fr-FR', { hour12: false });

  liste.prepend(noeud);

  // Le nœud cloné est détaché après insertion : on retrouve l'élément réel.
  return liste.querySelector('[data-attente]');
}

/** Dire où en est la réponse, sans quitter la ligne où elle s'écrira. */
function phaseAttente(item, phase, precision = '') {
  if (!item) return;

  const dit = precision !== '' ? `outil : ${precision}` : (PHASES[phase] ?? '');
  item.querySelector('[data-phase]').textContent = dit;
}

/** Le premier jeton chasse le squelette : il n'y a plus rien à faire patienter. */
function jetonAttente(item, texte) {
  if (!item) return;

  const corps = item.querySelector('[data-texte]');
  const squelette = corps.querySelector('.xo-skeleton');
  if (squelette) corps.textContent = '';

  corps.textContent += texte;
}

/**
 * Envoyer une demande, et écrire la réponse au fil des jetons.
 *
 * Le flux (SSE) transporte deux natures : des jetons, qui sont du texte et
 * s'accumulent ici, et des événements de forme (phase, outil) qui ne changent
 * qu'un libellé. Le tour complet est re-rendu par le serveur à la fin — ce qui
 * reste à l'écran est donc toujours du balisage PHP.
 */
async function envoyer(message) {
  if (!saisie || message === '') return;

  saisie.disabled = true;

  /* L'ancrage ne vaut que pour le premier message : une fois la dépêche versée
     au dossier, la renvoyer à chaque tour la remettrait au centre de la
     conversation quoi qu'on demande ensuite. */
  const depeche = saisie.dataset.depeche || '';
  saisie.dataset.depeche = '';

  /* L'attente prend la **forme du tour à venir** : même marqueur, même acteur,
     même place. Le texte s'y accumule au fil des jetons, et le tour rendu par
     le serveur le remplace sans secousse. */
  const attente = ouvrirAttente();
  document.getElementById('accueil')?.remove();
  defiler(true);      // on vient de parler : la réponse se formera sous les yeux

  try {
    const reponse = await fetch('api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(depeche ? { message, depeche } : { message }),
    });

    const lecteur = reponse.body.getReader();
    const decodeur = new TextDecoder();
    let tampon = '';

    for (;;) {
      const { done, value } = await lecteur.read();
      if (done) break;
      tampon += decodeur.decode(value, { stream: true });

      /* Une trame SSE se termine par une ligne vide. Le dernier morceau du
         tampon peut être une trame incomplète : on la garde pour le tour
         suivant plutôt que de la parser à moitié. */
      const trames = tampon.split('\n\n');
      tampon = trames.pop() ?? '';

      for (const trame of trames) {
        const ligne = trame.trim();
        if (!ligne.startsWith('data: ')) continue;

        let ev;
        try { ev = JSON.parse(ligne.slice(6)); } catch { continue; }

        if (ev.type === 'jeton') jetonAttente(attente, ev.texte);
        else if (ev.type === 'phase') phaseAttente(attente, ev.phase);
        else if (ev.type === 'outil_appel') {
          phaseAttente(attente, 'outil', ev.nom);
          /* Le compteur bouge dès que l'appel part, pas en fin de réponse : sur
             une question qui en enchaîne trois, c'est le seul moment où il a
             quelque chose à dire. Le nombre est provisoire — `appelerFils()` le
             réconcilie avec la base juste après. */
          majOutils({ enCours: outilsEtat.enCours + 1 });
        }
        else if (ev.type === 'error') notifier('danger', 'Réponse interrompue', ev.message, 8000);
      }
    }
  } catch (erreur) {
    notifier('danger', 'Agent injoignable', String(erreur.message || erreur), 6000);
  } finally {
    // Une réponse interrompue laisserait le compteur en « … » indéfiniment.
    majOutils({ enCours: 0 });
    saisie.disabled = false;

    attente?.remove();
    saisie.focus();

    /* On repose la conversation rendue plutôt que de recharger la page : un
       rechargement coupait l'antenne le temps de tout refaire, et perdait le
       défilement. Les segments déjà à l'écran survivent (voir `poserTours`). */
    const data = await appelerFils();
    if (data?.tours !== undefined) poserTours(data.tours);
  }
}

/**
 * Le champ accepte deux choses sans les distinguer à l'œil : une question pour
 * le modèle, et une commande. Obliger à choisir un mode avant de taper, ce
 * serait demander de savoir avant de commencer.
 */
/* Dès la première frappe, la conversation prend la main : être coupé en pleine
   saisie par un segment qui pousse le flux est exactement ce qu'il faut éviter. */
saisie?.addEventListener('input', laMainALaConversation);
saisie?.addEventListener('focus', laMainALaConversation);

saisie?.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  laMainALaConversation();

  const dit = saisie.value.trim();
  if (dit === '') return;
  saisie.value = '';

  if (dit.startsWith('/')) {
    const [nom, ...reste] = dit.slice(1).split(/\s+/);
    commander(nom.toLowerCase(), null, 'champ', reste.join(' '));
    return;
  }

  envoyer(dit);
});

/* Les suggestions de l'accueil : elles remplissent le champ et l'envoient. Une
   suggestion peut être une commande (`/veille`) comme une phrase — le champ ne
   fait pas la différence, elles n'ont donc pas à la faire non plus. */
for (const bouton of document.querySelectorAll('[data-suggestion]')) {
  bouton.addEventListener('click', () => {
    const dit = bouton.dataset.suggestion;
    if (dit.startsWith('/')) commander(dit.slice(1), null, 'suggestion');
    else envoyer(dit);
  });
}

/* ==========================================================================
   Le direct — l'agent qui parle sans qu'on demande.

   Une seule promesse tient tout : **jamais plus de dix-sept secondes de blanc**.
   Elle n'est pas confiée au réseau ni au modèle. Le serveur compose un segment
   en quelques millisecondes ; ici, on demande le suivant bien avant l'échéance,
   et un chien de garde vérifie qu'il est arrivé. Si rien n'est venu à temps, on
   le dit à l'antenne — un direct qui annonce sa panne reste un direct, un
   direct muet n'en est plus un.
   ========================================================================== */

const BUDGET = Number(app?.dataset.budget || 17) * 1000;

/* On relance bien avant l'échéance : le temps de composer et de poser doit
   tenir dans la marge, pas la consommer. Deux tiers du budget laissent de quoi
   rattraper un aller-retour lent sans jamais atteindre la limite. */
const CADENCE = Math.round(BUDGET * 0.66);

const direct = {
  antenne: app?.dataset.antenne === '1',
  minuteur: null,
  garde: null,
  dernier: 0,
  voixEchecs: 0,
  voixCoupee: false,
  // La conversation préempte l'antenne : tant que ce jalon est dans le futur,
  // aucun segment ne part.
  repriseA: 0,
};

/* Combien de temps la conversation garde la main après le dernier mot.
   Assez pour lire une réponse et enchaîner une question sans être coupé, assez
   peu pour que l'antenne reprenne d'elle-même quand on a fini — c'est ce délai
   qui remplace la bascule manuelle. */
const GARDE_CONVERSATION = 45_000;

/**
 * La conversation prend la main.
 *
 * Appelée dès la première frappe, pas seulement à l'envoi : être interrompu en
 * pleine saisie par un segment qui pousse le flux est exactement ce qu'on veut
 * éviter.
 */
/**
 * L'étiquette du régime, tenue à un seul endroit.
 *
 * Trois bouts de code la retouchaient chacun à sa façon — le passage en
 * conversation, l'arrivée d'un segment, le chien de garde. Ils auraient
 * divergé au premier changement de libellé.
 */
function majRegime(enDirect) {
  const badge = document.getElementById('etat-regime');
  if (!badge) return;

  badge.textContent = enDirect ? '● EN DIRECT' : '○ CONVERSATION';
  badge.className = enDirect
    ? 'xo-badge xo-badge--solid xo-badge--danger'
    : 'xo-badge xo-badge--solid xo-badge--success';

  // L'étiquette est la bascule : ce qu'elle déclenche doit suivre ce qu'elle
  // affiche, sinon un clic ferait l'inverse de ce qu'on lit.
  badge.dataset.action = enDirect ? 'conversation' : 'direct';
}

function laMainALaConversation() {
  if (!direct.antenne) return;
  direct.repriseA = Date.now() + GARDE_CONVERSATION;
  texte('etat-sondage', 'conversation');
  majRegime(false);
}

/** L'antenne a-t-elle la parole en ce moment ? */
function antenneParle() {
  return direct.antenne && Date.now() >= direct.repriseA;
}

async function basculerAntenne(ouvrir) {
  try {
    const reponse = await fetch(`api/direct.php?action=${ouvrir ? 'ouvrir' : 'fermer'}`);
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'bascule refusée');

    if (!ouvrir && data.bilan?.segments > 0) {
      notifier('success', 'Antenne fermée',
        `${data.bilan.segments} segments, ${data.bilan.sujets} sujets. Note de quart versée au fil.`, 8000);
    }

    /* Plus de rechargement.
       Depuis que le flux est unique, la page ne dépend plus du régime : seuls
       l'étiquette et la boucle changent. Recharger faisait clignoter tout
       l'écran pour deux mots — et le geste est devenu fréquent depuis que
       l'étiquette elle-même est la bascule. */
    direct.antenne = ouvrir;
    direct.repriseA = 0;
    majRegime(ouvrir);

    clearInterval(direct.minuteur);
    clearInterval(direct.garde);

    if (ouvrir) {
      direct.dernier = Date.now();
      segment();
      direct.minuteur = setInterval(segment, CADENCE);
      direct.garde = setInterval(veiller, 2000);
    } else {
      texte('etat-sondage', 'en écoute');
      // La note de quart vient d'entrer dans le fil : on la montre.
      const data2 = await appelerFils();
      if (data2?.tours !== undefined) poserTours(data2.tours);
    }
  } catch (erreur) {
    notifier('danger', 'Bascule impossible', String(erreur.message || erreur), 6000);
  }
}

/* La même liste que la conversation : un seul flux, une seule chronologie. */
const liste = document.getElementById('flux-liste');

async function segment() {
  if (!antenneParle() || !liste) return;

  try {
    const reponse = await fetch('api/direct.php?action=segment');
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'segment indisponible');

    // Le premier segment chasse l'accueil : il n'y a plus rien à proposer,
    // quelque chose se passe.
    document.getElementById('accueil')?.remove();

    /* Le plus récent en tête : on regarde un direct par le haut.

       Insérer au-dessus décale tout ce qui suit : quelqu'un en train de lire
       trois lignes plus bas voyait le texte glisser sous ses yeux toutes les
       onze secondes. On compense en descendant le défilement de la hauteur
       ajoutée — la vue ne bouge pas, seul le contenu s'accumule au-dessus. */
    const flux = document.getElementById('flux');
    const enTete = !flux || flux.scrollTop < 40;
    const hauteurAvant = flux ? flux.scrollHeight : 0;
    const positionAvant = flux ? flux.scrollTop : 0;

    const morceau = document.createElement('template');
    morceau.innerHTML = data.html;
    liste.prepend(morceau.content);
    mount(liste);
    selectionUnique(selection());   // le choix de l’utilisateur, pas la dernière liste montée

    /* Position **absolue**, pas un incrément.

       `mount()` monte la liste du segment ; XOSHUI y sélectionne la première
       ligne et fait un `scrollIntoView` dessus, ce qui ramène le flux à zéro
       avant qu'on ait pu compenser. Ajouter la hauteur à un défilement déjà
       détruit ne rattrapait rien — mesuré : on revenait de 300 px à 0 à chaque
       segment, soit toutes les onze secondes. */
    if (flux && !enTete) {
      flux.scrollTop = positionAvant + (flux.scrollHeight - hauteurAvant);
    }

    direct.dernier = Date.now();
    texte('etat-sondage', `direct · ${data.nature} · ${data.ms} ms`);
  majRegime(true);      // l'antenne a repris : l'étiquette doit le dire

    // La voix part maintenant, sans être attendue : le segment est déjà à
    // l'antenne, la phrase le rejoindra si elle arrive à temps.
    voix();

    /* Le flux est un direct, pas une archive : au-delà, le DOM devient le
       goulot et la mémoire du navigateur avec lui. */
    while (liste.children.length > 60) liste.lastElementChild.remove();
  } catch (erreur) {
    texte('etat-sondage', 'direct · segment manqué');
  }
}

/**
 * La voix du modèle sur le segment qui vient de passer.
 *
 * Volontairement pas attendue (`await` absent chez l'appelant) : le flux ne
 * doit jamais dépendre d'elle. Elle se pose dans un conteneur que PHP a déjà
 * rendu — le navigateur ne fait qu'y écrire un texte.
 *
 * Trois échecs d'affilée et l'on cesse de demander : un moteur déchargé ne se
 * rechargera pas parce qu'on insiste toutes les onze secondes, et chaque appel
 * perdu coûte un aller-retour au serveur.
 */
async function voix() {
  if (direct.voixCoupee) return;

  try {
    const reponse = await fetch('api/direct.php?action=voix');
    const data = await reponse.json();
    if (!data.ok || !data.voix) {
      direct.voixEchecs++;
      if (direct.voixEchecs >= 3) {
        direct.voixCoupee = true;
        notifier('info', 'Voix coupée', "Le modèle ne suit pas la cadence : le direct continue sans lui.", 6000);
      }
      return;
    }

    direct.voixEchecs = 0;
    const cible = liste?.querySelector(`[data-rang="${data.rang}"] [data-voix]`);
    if (!cible) return;          // le segment est déjà sorti du flux : tant pis
    cible.textContent = data.voix;
    // Le conteneur porte le glyphe et l'état caché : c'est lui qu'on révèle,
    // pas le seul texte.
    cible.closest('[hidden]').hidden = false;
  } catch {
    direct.voixEchecs++;
  }
}

/**
 * Le chien de garde.
 *
 * Il ne remplace pas la cadence : il constate qu'elle a échoué. Une antenne qui
 * dépasse son budget doit le dire à l'écran plutôt que de rester silencieuse —
 * c'est la seule façon de distinguer « rien à dire » de « quelque chose est
 * cassé », et les deux ne se traitent pas pareil.
 */
function veiller() {
  /* Pendant que la conversation a la main, le silence de l'antenne est voulu :
     ce n'est pas un blanc, et le signaler comme une panne serait faux. */
  if (!antenneParle()) {
    if (direct.antenne && Date.now() < direct.repriseA) direct.dernier = Date.now();

    return;
  }

  const blanc = Date.now() - direct.dernier;
  if (blanc <= BUDGET) return;

  /* Le blanc se dit dans la ligne d'état, pas sur l'étiquette : celle-ci
     répond à « quel régime ? », et l'antenne est toujours en direct — c'est
     elle qui est en peine, pas le régime qui a changé. */
  texte('etat-sondage', `direct · blanc de ${Math.round(blanc / 1000)} s`);
  segment();
}

if (direct.antenne) {
  direct.dernier = Date.now();
  segment();
  direct.minuteur = setInterval(segment, CADENCE);
  direct.garde = setInterval(veiller, 2000);
}

/* --- Le Newsdesk ----------------------------------------------------------
   Les onglets sont montés par XOSHUI ; on ne fait qu'écouter lequel s'ouvre
   pour rafraîchir ses données. Rafraîchir les trois en permanence ferait
   travailler le serveur pour des panneaux que personne ne regarde. */

/* Les onglets dont le contenu vient du serveur. `inspecte` n'y est pas : il ne
   se recharge pas tout seul, il montre la dernière ligne inspectée. */
/* Un onglet, une clé de réponse, un conteneur. Les trois marquages seulement :
   Inspecté, la veille et les outils sont fixes, ils ne s'ouvrent pas. */
const ONGLETS = {
  suivis: 'desk-suivis',
  traites: 'desk-traites',
  ecartes: 'desk-ecartes',
};

document.querySelector('[data-xo-tabs]')?.addEventListener('click', async (e) => {
  const onglet = e.target.closest('[data-rafraichir]');
  if (!onglet) return;

  const quoi = onglet.dataset.rafraichir;

  if (!ONGLETS[quoi]) return;

  const data = await fetch('api/fils.php').then((r) => r.json()).catch(() => null);
  if (data?.ok) {
    remplir(ONGLETS[quoi], data[quoi]);
    majComptes(data.statuts);
  }
});

/** Les comptes des marquages, tenus à jour après chaque geste. */
function majComptes(statuts) {
  if (!statuts) return;

  for (const [statut, n] of Object.entries(statuts)) {
    const cible = document.querySelector(`[data-compte="${statut}"]`);
    if (cible) cible.textContent = n;
  }

}

/**
 * Montrer une dépêche dans la zone Inspecté, en tête du Newsdesk.
 *
 * Elle est fixe : rien à ouvrir, rien à retrouver. Une demande en cours est
 * abandonnée si une autre part — en parcourant une liste au clavier, seule la
 * dernière ligne compte, et les réponses arrivées dans le désordre feraient
 * clignoter le détail.
 */
let inspecteEnCours = null;

async function montrerInspecte(id) {
  if (!id || inspecteEnCours === id) return;
  inspecteEnCours = id;

  try {
    const data = await fetch(`api/apercu.php?type=depeche&id=${encodeURIComponent(id)}`)
      .then((r) => r.json());
    if (data.ok && inspecteEnCours === id) remplir('desk-inspecte', data.html);
  } catch {
    /* L'inspecteur est un confort : son échec ne casse ni la liste ni le flux. */
  }
}

/* --- L'onglet Outils ------------------------------------------------------
   Ce que l'agent a fait, et de quoi le refaire. Chaque geste dépend du type
   d'outil : le serveur a déjà grisé ceux qui n'ont pas de sens, on n'a qu'à
   répondre à ceux qui restent. */

document.getElementById('desk-outils')?.addEventListener('click', async (e) => {
  const bouton = e.target.closest('[data-outil-geste]');
  if (!bouton || bouton.disabled) return;

  const ligne = bouton.closest('[data-appel]');
  const nom = ligne.dataset.outil;
  const valeur = ligne.dataset.valeur ?? '';

  // « Voir » ne relance rien : il repose la tuile de cet appel, telle qu'elle
  // serait aujourd'hui. « Rejouer » interroge à nouveau — l'actualité a bougé.
  if (bouton.dataset.outilGeste === 'voir') {
    await poserTuile('veille', valeur !== '' ? { q: valeur } : {});

    return;
  }

  await lancerOutil(nom, valeur);
});

/**
 * Le formulaire suit le schéma de l'outil choisi.
 *
 * Les champs et leurs aides viennent des définitions données au modèle, portées
 * dans l'attribut par PHP : un outil sans paramètre n'affiche pas de champ, et
 * on ne demande jamais ce dont l'outil n'a pas besoin.
 */
const formulaire = document.querySelector('[data-outil-formulaire]');
const schemaOutils = formulaire ? JSON.parse(formulaire.dataset.schema) : {};

function majFormulaireOutil() {
  const nom = document.getElementById('outil-nom')?.value;
  const champ = document.getElementById('outil-valeur');
  const schema = schemaOutils[nom];
  if (!champ || !schema) return;

  const sansArgument = schema.champ === null;
  champ.closest('.xo-search').hidden = sansArgument;
  champ.placeholder = schema.champ ?? '';
  champ.required = Boolean(schema.requis);
  texte('outil-aide', sansArgument ? 'Cet outil ne prend aucun argument.' : schema.aide);
}

document.getElementById('outil-nom')?.addEventListener('change', majFormulaireOutil);
majFormulaireOutil();

document.getElementById('outil-lancer')?.addEventListener('click', () => {
  lancerOutil(
    document.getElementById('outil-nom')?.value,
    document.getElementById('outil-valeur')?.value ?? '',
  );
});

document.getElementById('outil-valeur')?.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  document.getElementById('outil-lancer')?.click();
});

/**
 * Lancer un outil, et laisser sa trace.
 *
 * Le résultat entre dans le fil comme un tour : c'est la règle 6, et c'est ce
 * qui le rend utile au tour suivant du modèle.
 */
async function lancerOutil(nom, valeur) {
  if (!nom) return;
  occupe(`outil : ${nom}…`);

  try {
    const url = new URLSearchParams({ nom, valeur });
    const reponse = await fetch(`api/outil.php?${url}`);
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'outil refusé');

    poserTours(data.tours);
    remplir('desk-outils', data.outils);
    majOutils({ compte: data.compte, echecs: data.echecs, enCours: 0 });
  } catch (erreur) {
    notifier('danger', 'Outil impossible', String(erreur.message || erreur), 5000);
  } finally {
    occupe(null);
  }
}

/* Chercher dans la veille depuis l'onglet, sans convoquer de tuile. */
const champVeille = document.getElementById('desk-q');
let minuteurVeille = null;

champVeille?.addEventListener('input', () => {
  clearTimeout(minuteurVeille);
  // Une frappe ne doit pas déclencher une requête par caractère.
  minuteurVeille = setTimeout(async () => {
    const q = champVeille.value.trim();
    const url = new URLSearchParams({ type: 'veille', apercu: '1', ...(q !== '' ? { q } : {}) });
    const data = await fetch(`api/apercu.php?${url}`).then((r) => r.json()).catch(() => null);
    if (data?.ok) remplir('desk-veille', data.html);
  }, 300);
});

/* --- Les réglages --------------------------------------------------------- */

document.getElementById('r-enregistrer')?.addEventListener('click', async () => {
  const corps = new URLSearchParams({
    utilisateur: document.getElementById('r-utilisateur')?.value ?? '',
    modele: document.getElementById('r-modele')?.value ?? '',
    temperature: document.getElementById('r-temperature')?.value ?? '',
    outils: document.getElementById('r-outils')?.checked ? '1' : '0',
  });

  try {
    const reponse = await fetch('api/reglages.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corps,
    });
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'enregistrement refusé');

    // Rechargement : le nom change l'avatar, le modèle change la barre d'état.
    // C'est au serveur de refaire la page, pas au navigateur de la retoucher.
    location.reload();
  } catch (erreur) {
    texte('r-etat', String(erreur.message || erreur));
  }
});

/* --- La collecte, en fond -------------------------------------------------
   Il n'y a plus d'écran de veille à rafraîchir, mais la collecte tourne : le
   sondage entretient le cycle et la barre d'état. Les tuiles posées, elles, ne
   bougent pas — elles sont la trace d'un moment, pas un direct. */

let enCours = false;

async function sonder(forcer = false) {
  if (enCours) return;
  enCours = true;

  const temoin = document.getElementById('temoin');
  if (temoin) temoin.style.visibility = 'visible';

  try {
    const reponse = await fetch(`api.php?action=${forcer ? 'cycle' : 'etat'}`);
    const data = await reponse.json();
    if (!data.ok) throw new Error(data.erreur || 'réponse illisible');

    const s = data.stats;
    texte('etat-h1', s.h1);
    texte('horloge', data.heure);
    texte('etat-cycle', duree(data.cycle.ms || 0));
    texte('etat-pied', `${s.articles} dépêches · ${s.groupes} événements`);

    const cible = document.getElementById('etat-sources');
    if (cible) {
      cible.textContent = `${s.sources.saines || 0}/${s.sources.total || 0}`;
      cible.className = (s.sources.mortes || 0) > 0 ? 'xo-warning' : 'xo-success';
    }

    texte('etat-sondage', 'en écoute');
  } catch (erreur) {
    texte('etat-sondage', 'hors ligne');
  } finally {
    enCours = false;
    if (temoin) temoin.style.visibility = 'hidden';
  }
}

const periode = () => (document.hidden
  ? Number(app?.dataset.sondageInactif || 60)
  : Number(app?.dataset.sondage || 12)) * 1000;

/* L'horloge bat à la seconde, alignée sur la suivante plutôt que sur un
   intervalle fixe, qui glisserait de quelques millisecondes à chaque tour. */
const horloge = document.getElementById('horloge');
if (horloge) {
  const battre = () => {
    horloge.textContent = new Date().toLocaleTimeString('fr-FR', { hour12: false });
    setTimeout(battre, 1000 - (Date.now() % 1000));
  };
  battre();
}

document.getElementById('temoin')?.style.setProperty('visibility', 'hidden');
/* Au chargement, rien n'est choisi : les listes montées par XOSHUI ont désigné
   leur première ligne, mais personne n'a rien désigné. Les gestes de desk
   restent donc inertes, et le libellé dit « aucune ligne » — ce qui est vrai. */
selectionUnique(null);
majGestes();

/* Un rechargement repart du haut : le plus récent y est.

   Le navigateur, lui, cherche à restaurer le défilement d'avant. Sur un flux
   dont le contenu a changé entre-temps — l'antenne a parlé, des tours se sont
   ajoutés — cette position ne désigne plus rien. Mesuré : 600 px avant F5,
   2213 px après, au milieu de nulle part.

   La restauration se fait après l'exécution des modules, d'où le second appel
   sur `load` : la couper ne suffit pas, il faut aussi reprendre la main une
   fois qu'elle aurait eu lieu. */
history.scrollRestoration = 'manual';
defiler(true);
addEventListener('load', () => defiler(true));

appelerFils();
sonder();
setInterval(() => sonder(), periode());
