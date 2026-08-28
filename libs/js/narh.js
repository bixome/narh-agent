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

/* Sur quoi chaque commande agit, telle que `Ecran::COMMANDES` le déclare. La
   barre et le menu filtrent par `data-natures` porté sur leurs boutons ; la
   palette et le champ n'ont pas d'élément porteur, d'où cette table. */
const natures = (() => {
  try {
    return JSON.parse(app?.dataset.natures || '{}');
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

  /* La dernière porte, celle qu'aucun filtre d'affichage ne couvre : la palette
     et le champ n'ont pas d'élément à filtrer, et `/oublier` tapé pendant qu'une
     dépêche est choisie repartait vers la suppression d'un fil — sur
     l'identifiant d'un article, donc sur un fil qui n'a rien à voir.

     On refuse en nommant ce qu'il aurait fallu choisir : « rien de sélectionné »
     laissait croire à un oubli de clic alors que la ligne était bien là, mais de
     la mauvaise nature. */
  const attendues = natures[action];
  if (attendues !== undefined) {
    const dit = { depeche: 'une dépêche', evenement: 'un événement', fil: 'un fil', passage: 'un passage' };
    const liste = attendues.split(/\s+/);

    if (!cible) {
      notifier('info', 'Aucune ligne visée',
        `« ${action} » demande ${liste.map((n) => dit[n] ?? n).join(' ou ')}.`, 4000);
      return;
    }
    if (!liste.includes(cible.dataset.nature)) {
      notifier('info', 'Pas la bonne ligne',
        `« ${action} » demande ${liste.map((n) => dit[n] ?? n).join(' ou ')}, `
        + `pas ${dit[cible.dataset.nature] ?? cible.dataset.nature}.`, 5000);
      return;
    }
  }

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
    case 'conduites':
    case 'outils':
      await poserTuile(action);
      return;

    /* L'aide n'est pas une tuile : elle ne parle ni du fil ni de la veille, et
       n'a rien à laisser dans la chronologie. Une modale qu'on ferme d'Échap,
       comme XOSHUI la sert déjà à `?`. */
    case 'aide':
      document.getElementById('aide')?.showModal();
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
        if (suite?.tours !== undefined) poserTours(suite.tours, suite.dialogue);

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
        if (data?.tours !== undefined) poserTours(data.tours, data.dialogue);
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
        if (data?.tours !== undefined) poserTours(data.tours, data.dialogue);
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

/* Le menu contextuel est déclaré **une seule fois** pour tout l'écran — un menu
   par ligne multiplierait le balisage par trois cents pour un menu visible à la
   fois. Mais il s'ouvre sur cinq natures différentes, et proposait donc
   « suivre » sur un fil de conversation et « oublier » sur une dépêche.

   On filtre au moment de l'ouverture, en capture : XOSHUI place et montre le
   menu sur ce même `contextmenu`, et il doit le trouver déjà taillé. */
document.addEventListener('contextmenu', (e) => {
  const ligne = e.target.closest('[role="option"]');
  const menu = document.getElementById('menu-narh');
  if (!ligne || !menu) return;

  let visibles = 0;
  for (const item of menu.querySelectorAll('[data-natures]')) {
    item.hidden = !accepte(item, ligne.dataset.nature);
    if (!item.hidden) visibles++;
  }

  /* Un menu vide vaut pas de menu : sur une ligne de journal, aucun geste ne
     s'applique, et une boîte vide au clic droit passe pour un défaut. */
  if (visibles === 0) menu.hidden = true;
}, true);

/* 1. Le clic droit sur une ligne : XOSHUI ouvre le menu et émet xo:menu.

   `detail.item` est exigé, et c'est ce qui garde le geste unique. Le menu est
   partagé par les deux contenants qui le revendiquent — le flux et le Newsdesk
   (voir `Ecran::rendre()`) — et XOSHUI câble un écouteur de clic par contenant :
   un seul choix émet donc `xo:menu` deux fois, une fois depuis celui qui tient
   la ligne visée, une fois depuis l'autre, à vide. Sans ce filtre, le second
   tir se rabattait sur `selection()` — la même ligne — et la commande partait
   en double : deux marquages, deux lignes de journal, deux interrogations du
   modèle pour un clic.

   Un menu **contextuel** a un contexte par définition : sans cible désignée, il
   n'y a pas de choix à jouer. Le repli sur `selection()` reste juste pour les
   portes qui n'ont pas d'élément à donner — la palette et le champ. */
document.addEventListener('xo:menu', (e) => {
  if (!e.detail.item) return;
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

  /* Chaque geste ne se montre que sur ce qu'il sait traiter.
     Les huit s'affichaient sur les cinq natures sélectionnables, et sept n'en
     acceptent que deux. Ce n'était pas qu'un encombrement : « suivre » sur un
     fil envoyait son identifiant à une route qui attend celui d'une dépêche,
     et marquait un sujet sans rapport, sans lever d'erreur. */
    for (const bouton of barre.querySelectorAll('[data-natures]')) {
      bouton.hidden = !accepte(bouton, cible?.dataset.nature);
    }
}

/** La commande porte-t-elle cette nature ? Déclaré par `Ecran::COMMANDES`. */
function accepte(element, nature) {
  if (!nature) return false;

  return (element.dataset.natures || '').split(/\s+/).includes(nature);
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
    /* Choisir une ligne **plonge** dans son événement au lieu d'ouvrir une
       zone au-dessus de la liste. C'est le même geste qu'avant et le même
       contenu ; ce qui change est qu'il ne coûte plus de hauteur à la liste —
       mesuré, l'ancienne zone en prenait 219 px sur 826 dès le premier clic.

       Seulement depuis la liste du desk : une ligne choisie dans une tuile du
       fil ou dans un segment d'antenne ne doit pas détourner le poste de
       travail vers un sujet qu'on n'y regardait pas. */
    if (choisie.closest('#desk-liste')) {
      plonger(choisie.dataset.value, 1);
    }

    /* La plongée se déclenche ici, sur un choix réel, et non dans
       `majGestes()` sur l'état de la sélection : XOSHUI marque déjà une option
       comme choisie au montage de ses listes, si bien qu'au chargement une
       ligne est « sélectionnée » sans que personne l'ait désignée — le desk
       s'ouvrirait alors d'entrée sur un sujet qu'on n'a pas demandé. */
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

    poserTours(data.tours, data.dialogue);
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
function poserTours(html, dialogue) {
  /* Le dialogue d'abord, et séparément : c'est lui qu'on attend après avoir
     tapé. Il vit dans la colonne de l'agent — on parle à quelqu'un, la réponse
     arrive là où l'on a parlé — tandis que les tuiles et les notes de quart
     restent au desk, où l'on travaille. Le serveur rend les deux moitiés
     déjà triées : le navigateur n'a pas à connaître les rôles (règle 2). */
  if (dialogue !== undefined && parole) {
    parole.innerHTML = dialogue;
    mount(parole);
    const flux = document.getElementById('agent-flux');
    if (flux) flux.scrollTop = 0;
  }

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
  // Les tuiles Outils qui viennent d'entrer : leur aide se remplit à
  // l'insertion, pas au premier clic sur le select.
  monterFormulairesOutil(liste);
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
  /* La colonne de l'agent d'abord : ce qu'on provoque en tapant s'y écrit, et
     c'est là qu'on regarde. Le desk garde son propre défilement — un segment
     d'antenne qui arrive ne doit pas remonter la conversation. */
  const parlant = document.getElementById('agent-flux');
  if (parlant && (force || parlant.scrollTop < 40)) parlant.scrollTop = 0;

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
    remplir('compteurs', data.compteurs);
    remplir('jauge-contexte', data.contexte);

    /* Plus de `remplir('desk-outils')` : le poste de commande était un panneau
       fixe du Newsdesk qu'il fallait refaire à la main. C'est une tuile, et
       une tuile se refait avec le fil — `poserTours()` s'en charge pour toutes
       à la fois. Le compteur, lui, reste tenu ici : il vit dans la rangée du
       champ, qui ne se repose jamais. */
    majOutils({ compte: data.compte, echecs: data.echecs, enCours: 0 });

    /* « chargé » et « en ligne » sont deux choses : un modèle installé mais
       déchargé répond, en payant d'abord son chargement en mémoire. */
    const etatModele = document.getElementById('etat-modele');
    const m = data.moteur;
    const dit = !m.en_ligne ? 'hors ligne' : (m.charge ? m.modele : `${m.modele} · déchargé`);
    if (etatModele) {
      etatModele.textContent = dit;
      /* `xo-opt` est conservé : il dit que cette mention peut s'effacer quand
         la bande se resserre, et remplacer la classe entière l'effaçait, lui.
         Le ton change à chaque sondage, le rôle non — écraser l'un avec
         l'autre remettait le nom du modèle en travers d'un écran de 375 px,
         douze secondes après le chargement, sans que rien ne le montre en
         développement. Un attribut de classe qui porte deux choses ne se
         réécrit pas en bloc. */
      etatModele.className = 'xo-opt '
        + (m.en_ligne ? (m.charge ? 'xo-success' : 'xo-muted') : 'xo-warning');
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
  if (data?.tours !== undefined) poserTours(data.tours, data.dialogue);
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
  /* Dans la colonne de l'agent, et non dans le desk : c'est là qu'on vient de
     taper, et c'est là que la réponse doit se former. Depuis que le fil a
     rejoint le desk, l'attente s'ouvrait à gauche pendant qu'on regardait à
     droite — les jetons s'écrivaient hors du champ de vision. */
  if (!gabarit || !parole) return null;

  const noeud = gabarit.content.cloneNode(true);
  const item = noeud.querySelector('[data-attente]');

  item.dataset.quand = Math.floor(Date.now() / 1000);
  item.querySelector('[data-heure]').textContent = new Date().toLocaleTimeString('fr-FR', { hour12: false });

  parole.prepend(noeud);

  // Le nœud cloné est détaché après insertion : on retrouve l'élément réel.
  return parole.querySelector('[data-attente]');
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
    if (data?.tours !== undefined) poserTours(data.tours, data.dialogue);
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

  /* La palette dit lequel des deux régimes a la parole. Elle les proposait à
     égalité, alors que l'un est toujours déjà en cours — et c'est la seule
     porte qui n'affichait aucun état. Du texte dans une place rendue par le
     serveur, comme partout ailleurs (règle 2). */
  const enCours = enDirect ? 'direct' : 'conversation';
  for (const li of document.querySelectorAll('[data-regime]')) {
    const place = li.querySelector('[data-regime-etat]');
    if (place) place.textContent = li.dataset.regime === enCours ? ' · en cours' : '';
  }
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
      if (data2?.tours !== undefined) poserTours(data2.tours, data2.dialogue);
    }
  } catch (erreur) {
    notifier('danger', 'Bascule impossible', String(erreur.message || erreur), 6000);
  }
}

/* Une seule chronologie (règle 7), lue à deux endroits : `liste` porte ce qui
   est matière de travail — tuiles, notes de quart, segments d'antenne — dans
   le desk, et `parole` porte le dialogue dans la colonne de l'agent. Le
   partage se décide côté serveur (`Vue::tours()`), pas ici. */
const liste = document.getElementById('flux-liste');
const parole = document.getElementById('agent-liste');

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
/* Un onglet, une clé de réponse, un conteneur.

   Deux, et non plus quatre : suivi, traité et écarté sont un geste à trois
   issues, réunis sous « Marqués » (voir `Ecran::newsdesk()`). Trois onglets
   pour un même sujet débordaient sur une seconde ligne, en permanence, dans la
   colonne la plus étroite de l'écran. */
const ONGLETS = {
  veille: 'desk-veille',
  marques: 'desk-marques',
};

/* Le Fil n'est pas dans cette table, et c'est délibéré : il est **vivant**.
   `poserTours()` le tient à jour à chaque réponse, tuile ou outil, et le direct
   y insère ses segments à leur place. Le recharger au clic d'onglet le
   réécrirait depuis le serveur — donc sans les segments, qui n'existent que
   dans cette page — et l'antenne semblerait s'être interrompue. Les autres
   onglets, eux, sont des photos qu'il faut redemander. */

/** L'onglet actuellement ouvert, tel que XOSHUI le marque. */
function ongletOuvert() {
  return document.querySelector('.xo-tabs__tab[aria-selected="true"]')?.dataset.rafraichir ?? '';
}

/**
 * Refaire le contenu d'un onglet.
 *
 * Une seule règle décide de ce que montre `desk-veille`, et elle est ici : tant
 * qu'il y a des mots dans le champ, c'est la recherche qui le remplit ; sinon
 * c'est le flux. Écrite en deux endroits — le champ d'un côté, l'onglet de
 * l'autre — elle se contredisait : changer d'onglet écrasait le résultat de la
 * recherche **sans vider le champ**, et la requête restait affichée au-dessus
 * d'une liste qui ne lui correspondait plus.
 */
async function rafraichirDesk(quoi) {
  if (!ONGLETS[quoi]) return;

  const q = champVeille?.value.trim() ?? '';

  if (quoi === 'veille' && q !== '') {
    const url = new URLSearchParams({ type: 'veille', q });
    const data = await fetch(`api/apercu.php?${url}`).then((r) => r.json()).catch(() => null);
    if (data?.ok) remplir('desk-veille', data.html);

    return;
  }

  const data = await fetch('api/fils.php').then((r) => r.json()).catch(() => null);
  if (data?.ok) {
    remplir(ONGLETS[quoi], data[quoi]);
    majComptes(data.statuts);
    // Les lignes sont posées : on peut aller demander ce qu'on en dit ailleurs.
    croiserOsint(document.getElementById(ONGLETS[quoi]) ?? document);
  }
}

document.querySelector('[data-xo-tabs]')?.addEventListener('click', async (e) => {
  const onglet = e.target.closest('[data-rafraichir]');
  if (!onglet) return;

  const quoi = onglet.dataset.rafraichir;
  if (!ONGLETS[quoi]) return;

  /* La recherche ne porte que sur la veille — `api/apercu.php` cherche des
     dépêches, les trois autres onglets montrent ce qu'on a marqué. Partir vers
     l'un d'eux vide donc le champ : le laisser plein annoncerait un filtre qui
     ne s'applique plus à ce qu'on regarde.

     Revenir sur Veille ne le vide pas, et c'est ce qui permet au champ de s'y
     ramener lui-même : quand on tape depuis un autre onglet, il clique celui-ci
     et sa requête survit au passage. */
  if (quoi !== 'veille' && champVeille) champVeille.value = '';

  await rafraichirDesk(quoi);
});

/* --- La passe de croisement OSINT ----------------------------------------
   Après l'affichage, jamais pendant. Le desk pose ses lignes, puis on demande
   au serveur ce que des registres extérieurs en disent, et le verdict rejoint
   la carte qui est déjà à l'écran.

   C'est la règle de la lecture hors réponse, et pour le direct elle est
   vitale : un segment est composé en 30 à 45 ms parce qu'il n'attend rien, et
   la contrainte fondatrice est de ne jamais laisser plus de dix-sept secondes
   de blanc. Un appel réseau de deux secondes ne rentre pas dans ce budget.

   `api/liens.php` décrivait déjà ce motif pour la vérification des liens et
   n'a jamais eu d'appelant — un motif sans branchement reste une intention. */
let osintEnCours = false;

/**
 * Croiser les sujets à l'écran, **un par un**, en montrant où l'on en est.
 *
 * Un seul aller-retour aurait suffi à obtenir les verdicts, et n'aurait rien
 * montré : l'écran serait resté identique pendant six secondes, puis tout
 * serait apparu d'un coup. On ne saurait pas si le desk travaille, s'il a fini,
 * ou s'il est en panne — et ces trois états se ressemblent quand rien ne bouge.
 *
 * La boucle est donc côté navigateur, et c'est aussi ce qui évite une seconde
 * route qui streame : `api/chat.php` est la seule, et elle doit le rester. Le
 * cadencement appartient à celui qui affiche.
 *
 * La règle n'est pas assouplie pour autant — **rien n'attend le croisement**.
 * Les lignes sont déjà lisibles, complètes, avant que le premier appel parte ;
 * ce qui s'affiche pendant, c'est l'état du traitement, pas son résultat.
 */
async function croiserOsint(racine = document) {
  if (osintEnCours) return;

  /* Ce qui est **à l'écran**, et rien d'autre : croiser ce qu'on ne regarde
     pas ferait payer des requêtes sortantes à des services gratuits pour un
     verdict que personne ne lira. */
  const lignes = [...racine.querySelectorAll('[data-groupe]')]
    .filter((li) => !li.dataset.osintVu && li.querySelector('[data-osint]'))
    .slice(0, 12);
  if (lignes.length === 0) return;

  osintEnCours = true;
  let fait = 0;

  try {
    for (const li of lignes) {
      const place = li.querySelector('[data-osint]');
      /* `textContent` et non du balisage : la passe écrit un état, elle ne
         dessine pas (règle 2). La place a été rendue par le serveur, vide. */
      place.hidden = false;
      place.textContent = '⋯ croisement…';
      texte('etat-sondage', `croisement ${++fait}/${lignes.length}`);

      let rendu = null;
      try {
        const reponse = await fetch('api/osint.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ groupes: [Number(li.dataset.groupe)] }),
        });
        const data = await reponse.json();
        if (data?.ok) rendu = data.verdicts?.[li.dataset.groupe] ?? '';
      } catch {
        /* Un service injoignable n'est pas une panne du desk : la ligne reste
           ce qu'elle était, et on le dit sur elle plutôt que dans une
           notification qui recouvrirait l'écran pour un supplément. */
        place.textContent = '⋯ croisement injoignable';
        continue;
      }

      /* Marquée même sans verdict : la plupart des sujets n'ont rien à
         vérifier — l'USGS n'a rien à dire d'une garde à vue — et redemander à
         chaque sondage ce qu'aucun service ne sait dire coûterait une requête
         par ligne et par cycle. */
      li.dataset.osintVu = '1';

      if (rendu) {
        /* La réponse porte **tous** les verdicts connus du sujet, y compris
           ceux que le serveur a déjà rendus avec la ligne : on remplace, on
           n'ajoute pas. Sans cela le même croisement s'écrivait deux fois. */
        for (const vieux of li.querySelectorAll('[data-verdict]')) vieux.remove();
        place.remove();
        li.insertAdjacentHTML('beforeend', rendu);
      } else {
        /* Le vide ne se dit pas. « — rien à croiser » restait 1,2 s par ligne,
           et la boucle étant sérielle, quatre cartes le portaient en même temps
           à l'écran : une colonne de phrases qui n'apprennent rien, sur les
           trente-huit lignes de quarante qu'aucun service ne sait vérifier.
           C'est la règle que `Osint::croiser()` s'applique déjà au journal —
           un service qui ne s'applique pas ne laisse aucune trace — et l'écran
           n'a pas de raison d'être plus bavard que le journal. L'avancement se
           lit dans la barre d'état, qui est faite pour ça. */
        place.remove();
      }
    }
  } finally {
    texte('etat-sondage', '');
    osintEnCours = false;
  }
}

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
/* --- La pile de niveaux ---------------------------------------------------
   Le desk plonge au lieu d'empiler. Cliquer une ligne ouvre l'événement, qui
   **remplace** la liste ; de là on ouvre la source, puis sa vérification. On
   remonte à Échap, ou par le fil d'Ariane.

   Ce n'est pas un onglet : des onglets sont des pairs — Veille et OSINT le
   sont — alors qu'ici il s'agit de profondeur. En interface texte, la
   profondeur est une pile qu'on dépile, avec une trace d'une ligne.

   La pile vit **en mémoire de page**, pas en base : plonger est un geste de
   consultation, comme faire défiler. L'inscrire ferait du bruit dans une
   chronologie qui doit rester lisible quand on cherche ce qui s'est passé. */
const NIVEAUX = ['Veille', "l'événement", 'la source', 'la vérification'];
let pile = [];

function niveauCourant() {
  return pile.length;
}

/** Rendre la barre de chemin et montrer la bonne surface. */
function majNiveau() {
  const barre = document.getElementById('desk-barre');
  const liste = document.getElementById('desk-liste');
  const zone = document.getElementById('desk-niveau');
  const trace = document.getElementById('desk-trace');
  if (!barre || !liste || !zone || !trace) return;

  const profond = pile.length > 0;
  barre.hidden = !profond;
  liste.hidden = profond;
  zone.hidden = !profond;

  if (!profond) {
    zone.innerHTML = '';

    return;
  }

  /* Le chemin est bâti ici, en texte : c'est le seul endroit de l'écran où le
     navigateur compose du balisage, et il s'agit d'un état de navigation qui
     n'existe que dans cette page — le serveur ne le connaît pas et ne peut
     donc pas le rendre. */
  trace.replaceChildren();
  const marches = [{ titre: NIVEAUX[0], rang: 0 }, ...pile.map((p, i) => ({ titre: p.titre, rang: i + 1 }))];
  for (const m of marches) {
    const sep = document.createElement('span');
    sep.className = 'xo-breadcrumb__sep';
    sep.setAttribute('aria-hidden', 'true');
    sep.textContent = m.rang === 0 ? '' : '›';
    trace.append(sep);

    const el = document.createElement(m.rang === marches.length - 1 ? 'span' : 'a');
    el.textContent = m.titre;
    if (m.rang === marches.length - 1) {
      el.setAttribute('aria-current', 'page');
    } else {
      el.href = '#';
      el.addEventListener('click', (e) => { e.preventDefault(); remonterA(m.rang); });
    }
    trace.append(el);
  }
}

/** Descendre d'un niveau sur une dépêche. */
async function plonger(id, n = 1) {
  if (!id) return;

  const zone = document.getElementById('desk-niveau');
  if (!zone) return;

  // L'attente se dit : un niveau 2 va chercher un article sur le réseau, et
  // une surface vide pendant deux secondes se lit comme une panne.
  pile = pile.slice(0, n - 1);
  pile.push({ id, titre: NIVEAUX[n] ?? 'détail' });
  majNiveau();
  zone.textContent = '⋯ ouverture…';

  try {
    const url = new URLSearchParams({ type: 'niveau', n: String(n), id });
    const data = await fetch(`api/apercu.php?${url}`).then((r) => r.json());
    if (!data.ok) throw new Error(data.erreur || 'niveau illisible');

    pile[pile.length - 1].titre = data.titre || NIVEAUX[n];
    zone.innerHTML = data.html;
    mount(zone);
    majNiveau();
  } catch (erreur) {
    zone.textContent = '';
    notifier('danger', 'Niveau illisible', String(erreur.message || erreur), 4000);
    remonterA(pile.length - 1);
  }
}

/** Revenir à une profondeur donnée — 0 rend la liste. */
function remonterA(rang) {
  pile = pile.slice(0, rang);
  majNiveau();
  if (pile.length > 0) {
    plonger(pile[pile.length - 1].id, pile.length);
  }
}

document.getElementById('desk-remonter')?.addEventListener('click', () => remonterA(pile.length - 1));

/* Les descentes gardent **la dépêche du niveau 1**, pas celle du niveau
   courant : la source et la vérification parlent toutes deux de l'objet qu'on
   a désigné dans la liste, et non de la vue par laquelle on y est arrivé. */
document.addEventListener('click', (e) => {
  const bouton = e.target.closest?.('[data-plonger]');
  if (!bouton || pile.length === 0) return;
  plonger(pile[0].id, Number(bouton.dataset.plonger));
});

/* Échap remonte d'un niveau. XOSHUI ne s'en sert que pour refermer ses
   modales, et il n'y en a aucune d'ouverte quand on plonge — les deux usages
   ne peuvent donc pas se croiser. Ce n'est pas un raccourci maison vers une
   action : remonter est de l'état d'affichage, comme faire défiler. */
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape' || pile.length === 0) return;
  if (document.querySelector('dialog[open]')) return;
  e.preventDefault();
  remonterA(pile.length - 1);
});

/**
 * Montrer une dépêche — la porte que gardent les commandes.
 *
 * `inspecter` et les autres gestes visent une ligne où qu'elle soit, y compris
 * dans une tuile du fil : ils plongent donc au niveau 1, comme un clic dans la
 * liste. La zone « Inspecté » qu'ils remplissaient auparavant n'existe plus —
 * elle est devenue ce niveau.
 */
async function montrerInspecte(id) {
  await plonger(id, 1);
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
function majFormulaireOutil(form) {
  if (!form) return;

  // Le schéma voyage avec le formulaire : il en existe autant que de tuiles
  // posées, et chacune répond pour elle-même.
  let schemas = {};
  try {
    schemas = JSON.parse(form.dataset.schema || '{}');
  } catch {
    return;
  }

  const select = form.querySelector('[data-outil-nom]');
  const champ = form.querySelector('[data-outil-valeur]');
  const schema = schemas[select?.value];
  if (!champ || !schema) return;

  const sansArgument = schema.champ === null;
  champ.closest('.xo-search').hidden = sansArgument;
  champ.placeholder = schema.champ ?? '';
  champ.required = Boolean(schema.requis);

  const aide = form.parentElement?.querySelector('[data-outil-aide]');
  if (aide) aide.textContent = sansArgument ? 'Cet outil ne prend aucun argument.' : schema.aide;
}

/* Délégué au document, et non branché sur les éléments au chargement.

   Le formulaire était fixe, au pied du Newsdesk : on pouvait l'attacher une
   fois pour toutes. Il vit maintenant dans une tuile qu'on convoque, donc il
   n'existe pas quand ce fichier s'exécute — les trois `addEventListener` du
   démarrage ne trouvaient plus rien et « Lancer » restait inerte, sans une
   erreur pour le dire. La délégation vaut pour toutes les tuiles, y compris
   celles qui seront posées dans dix minutes. */
document.addEventListener('change', (e) => {
  const form = e.target.closest?.('[data-outil-formulaire]');
  if (form && e.target.matches('[data-outil-nom]')) majFormulaireOutil(form);
});

document.addEventListener('click', (e) => {
  const bouton = e.target.closest?.('[data-outil-lancer]');
  if (!bouton) return;

  const form = bouton.closest('[data-outil-formulaire]');
  lancerOutil(
    form?.querySelector('[data-outil-nom]')?.value,
    form?.querySelector('[data-outil-valeur]')?.value ?? '',
  );
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' || !e.target.matches?.('[data-outil-valeur]')) return;
  e.preventDefault();
  e.target.closest('[data-outil-formulaire]')?.querySelector('[data-outil-lancer]')?.click();
});

/* Une tuile fraîchement posée arrive avec son select sur le premier outil et
   son aide vide : on la met à jour à l'insertion, sans quoi « Cet outil ne
   prend aucun argument » n'apparaîtrait qu'après avoir touché au select. */
function monterFormulairesOutil(racine = document) {
  for (const form of racine.querySelectorAll?.('[data-outil-formulaire]') ?? []) {
    majFormulaireOutil(form);
  }
}

monterFormulairesOutil();

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

    // `poserTours` a déjà refait les tuiles Outils du fil : elles montrent
    // l'appel qu'on vient de lancer sans qu'on ait à les viser.
    poserTours(data.tours, data.dialogue);
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
  minuteurVeille = setTimeout(() => {
    /* Le champ est posé **au-dessus** des onglets, et se présente donc comme
       commandant la liste qu'on regarde. Il n'écrivait pourtant que dans
       `desk-veille` : taper depuis Suivis remplissait un panneau caché, et
       l'écran ne bougeait pas — une frappe sans effet visible, qu'on répète.

       Il tient maintenant sa promesse en ramenant la vue à ce qu'il sait
       faire. Chercher, c'est chercher dans la veille ; le dire en ouvrant
       l'onglet vaut mieux que de chercher dans le vide. */
    if (ongletOuvert() !== 'veille') {
      document.querySelector('.xo-tabs__tab[data-rafraichir="veille"]')?.click();

      return;
    }

    rafraichirDesk('veille');
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

/* Le croisement part **après** que la page soit à l'écran, pas pendant son
   chargement : c'est tout le principe de la passe, et le déclencher plus tôt
   reviendrait à faire attendre l'affichage derrière un appel réseau.

   Mais ce fichier est un module, donc différé : `load` a déjà tiré quand il
   s'exécute, et un écouteur posé ici n'aurait jamais rien reçu — mesuré, 81
   lignes croisables et zéro croisement. On regarde donc l'état plutôt que
   d'attendre un événement passé, comme XOSHUI le fait pour son montage. */
if (document.readyState === 'complete') {
    croiserOsint();
} else {
    addEventListener('load', () => croiserOsint());
}
