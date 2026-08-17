<?php
declare(strict_types=1);

/**
 * La coquille — une surface, et rien d'autre.
 *
 * NARH n'a plus d'écrans. Il a **une lecture** : la conversation. Une barre
 * d'état d'une ligne, le fil des tours, un champ en bas. Pas de barre latérale,
 * pas d'onglets, pas de colonnes permanentes.
 *
 * Ce qui a disparu n'est pas perdu : la veille, la mémoire, le journal,
 * l'inspecteur sont devenus des **tuiles** (`src/Tuile.php`) qui se posent dans
 * le fil quand on les demande — ou quand l'agent juge en avoir besoin pour
 * montrer d'où sort sa réponse. Un panneau permanent occupe l'écran en
 * permanence pour un contenu qu'on regarde par intermittence ; une tuile arrive
 * à l'endroit où la question a été posée, et y reste comme trace.
 *
 * Trois portes mènent aux commandes, et elles mènent toutes à `commander()`
 * (règle 5) : le champ (`/veille`), la palette (`Ctrl+K`), le clic droit sur une
 * ligne. Rien n'est branché ailleurs.
 */
final class Ecran
{
    /**
     * Les commandes, déclarées une fois.
     *
     * Le menu contextuel, la palette et le champ lisent tous cette table.
     * Ajouter une commande, c'est ajouter une ligne ici — elle apparaît du même
     * coup dans les trois portes, et aucune ne peut proposer un geste que les
     * autres ignorent.
     *
     * `surLigne` dit si la commande vise l'élément sélectionné dans une tuile :
     * le menu contextuel ne montre que celles-là. La phase, quand elle est là,
     * dit que la commande n'est pas branchée — l'écran doit le dire au lieu de
     * faire semblant.
     *
     * L'icône est celle du **rôle**, pas du dessin (voir `tools/icones.php`) :
     * une commande la porte partout où elle apparaît, palette et clic droit
     * compris. Déclarée ici, elle ne peut pas diverger d'une porte à l'autre.
     *
     * `court` est le verbe seul, pour la barre contextuelle : « Suivre
     * l'événement » y répéterait « l'événement » sept fois de suite, alors que
     * la ligne visée est nommée juste à côté.
     *
     * clé => [libellé, surLigne, phase, groupe, icône, court]
     */
    public const COMMANDES = [
        'direct'       => ['Passer en agent en direct',  false, '',   'Régime', 'direct', ''],
        'conversation' => ['Revenir en conversation',    false, '',   'Régime', 'antenne-fin', ''],
        'veille'       => ['Ouvrir la veille',           false, '',   'Tuiles', 'veille', ''],
        'alertes'      => ['Voir les alertes',           false, '',   'Tuiles', 'alerte', ''],
        'journal'      => ['Voir le journal',            false, '',   'Tuiles', 'journal', ''],
        'memoire'      => ['Voir les fils passés',       false, '',   'Tuiles', 'memoire', ''],
        'corpus'       => ['Chercher dans le corpus',    false, '',   'Tuiles', 'chercher', ''],
        'inspecter'    => ['Inspecter la ligne',         true,  '',   'Tuiles', 'inspecter', 'inspecter'],
        'suivi'        => ["Suivre l'événement",         true,  '',   'Veille', 'suivre', 'suivre'],
        'traite'       => ['Marquer traité',             true,  '',   'Veille', 'traite', 'traité'],
        'ecarte'       => ['Écarter',                    true,  '',   'Veille', 'ecarter', 'écarter'],
        'lire'         => ['Lire le texte ici',          true,  '',   'Veille', 'inspecter', 'lire'],
        'ouvrir'       => ["Ouvrir l'article",           true,  '',   'Veille', 'ouvrir', 'ouvrir'],
        'interroger'   => ["Interroger l'agent dessus",  true,  '',   'Veille', 'interroger', 'demander'],
        'relever'      => ['Relever maintenant',         false, '',   'Veille', 'relever', ''],
        'desancrer'    => ['Oublier la dépêche visée',   false, '',   'Conversation', 'ecarter', ''],
        'fil-neuf'     => ['Ouvrir un fil neuf',         false, '',   'Conversation', 'fil-neuf', ''],
        'oublier'      => ['Oublier le fil',             true,  '',   'Conversation', 'oublier', 'oublier'],
        'quart'        => ['Écrire la note de quart',    false, '',   'Conversation', 'journal', ''],
    ];

    /**
     * Le nom des rubriques de sources, tel qu'on le lit à l'écran.
     *
     * Elles ont survécu à la disparition de la barre de filtres : le direct les
     * annonce à l'antenne (« En bref — Économie »), et une tuile de veille peut
     * en cibler une. Un seul endroit les nomme, sinon « eco » s'afficherait
     * « Économie » ici et « Éco » là.
     */
    public const RUBRIQUES = [
        'tout'     => 'Toutes',
        'une'      => 'À la une',
        'france'   => 'France',
        'monde'    => 'Monde',
        'eco'      => 'Économie',
        'tech'     => 'Technologie',
        'sciences' => 'Sciences',
        'regional' => 'Régions',
        'veille'   => 'Veille ciblée',
        // Ce qui se discute, pas ce qui est établi — voir le rang `social`.
        'social'   => 'Web social',
    ];

    /**
     * Le contexte : ce que la barre d'état doit dire, et rien de plus.
     *
     * La collecte tourne qu'on la regarde ou non ; l'écran l'entretient au
     * passage, comme avant, mais il n'ouvre plus la veille pour l'afficher —
     * c'est une tuile qui s'en charge, quand on la demande.
     *
     * @return array<string, mixed>
     */
    public static function contexte(): array
    {
        $maintenant = time();
        $base = new Base((string) narh_reglage('base_veille'));
        $base->synchroniser(require NARH_RACINE . '/config/sources.php');

        $collecteur = new Collecteur($base);
        $stats = $base->stats($maintenant);

        $amorce = $stats['articles'] === 0;
        if ($amorce || (narh_reglage('collecte_web', true) && $collecteur->perime($maintenant) && !Collecteur::occupe())) {
            $rapport = $collecteur->cycle($amorce, $amorce ? 45 : (int) narh_reglage('cycle_max', 15));
            self::journaliserCycle($base, $rapport, 'écran');
            $maintenant = time();
            $stats = $base->stats($maintenant);
        }

        return [
            'maintenant' => $maintenant,
            'stats'      => $stats,
            'amorce'     => $amorce,
            'cycle'      => $base->cycle(),
            // Pour l'en-tête : ce qui doit rester sous les yeux sans qu'on le
            // demande. Court — trois lignes, pas un panneau.
            'alertes'    => $base->alertes($maintenant - 21600, Alerte::ALERTE, 3),
            // L'onglet Veille du Newsdesk : de quoi jeter un œil sans convoquer
            // une tuile. Court — c'est un aperçu, pas le fil.
            'recents'    => $base->arbre([], 12),
            /* Les gestes de desk ne partent pas dans le vide : suivre, traiter
               et écarter écrivent un état en base, et le Newsdesk le rend avec
               son compte. Sans cela, on marque sans jamais revoir ce qu'on a
               marqué — le geste n'a alors aucune conséquence lisible. */
            'statuts'    => $base->comptesStatuts(),
            'suivis'     => $base->arbre(['statut' => 'suivi'], 12),
            'traites'    => $base->arbre(['statut' => 'traite'], 12),
            'ecartes'    => $base->arbre(['statut' => 'ecarte'], 12),
            // L'onglet Outils : ce que l'agent a déjà fait dans ce fil.
            'appels'     => Memoire::outils(Agent::filId(), 20),
            // Plus de liste de fils en surface : un seul « fil » à l'écran, et
            // c'est celui de la veille. Les conversations passées se convoquent
            // par `/memoire`, comme le reste.
        ];
    }

    /**
     * Un cycle raconté dans la chronologie commune.
     *
     * Ici et pas dans `Collecteur` : le collecteur rend un rapport, il n'a pas à
     * savoir qu'un journal existe — et c'est ce qui permet de l'appeler depuis
     * le CLI, l'écran ou l'API en disant à chaque fois d'où l'on venait.
     *
     * @param array<string, mixed> $rapport
     */
    public static function journaliserCycle(Base $base, array $rapport, string $porte): void
    {
        if ($rapport['saute'] === true) {
            // Un cycle sauté n'est pas un incident : c'est le verrou qui fait
            // son travail. En parler à chaque sondage noierait le journal.
            return;
        }

        $niveau = ((int) $rapport['erreurs'] > 0) ? 'warn' : 'ok';
        if ((int) $rapport['nouveaux'] === 0 && (int) $rapport['erreurs'] === 0) {
            $niveau = 'info';
        }

        Journal::noter($niveau, 'collecte', sprintf(
            'cycle %s : %d source(s), %d neuve(s), %d alerte(s)%s',
            $porte,
            (int) $rapport['sources'],
            (int) $rapport['nouveaux'],
            (int) $rapport['alertes'],
            (int) $rapport['erreurs'] > 0 ? ', ' . (int) $rapport['erreurs'] . ' erreur(s)' : '',
        ), (int) $rapport['ms']);

        self::journaliserSaillances($base);

        Journal::rogner();
    }

    /**
     * Verser ce que la collecte a de saillant dans la chronologie unique.
     *
     * C'est le maillon qui manquait à la règle 7 : sans lui, le journal ne
     * contenait que « cycle : 27 sources, 2 neuves » — le fait qu'un cycle ait
     * eu lieu, jamais ce qu'il a trouvé. On ne pouvait donc pas y lire
     * « alerte à 04:30 → conduite déclenchée → note », puisque le premier
     * maillon n'y était pas.
     *
     * Appelée depuis `journaliserCycle()` et de nulle part ailleurs : le
     * paramètre `Base` y est passé exprès plutôt que d'ajouter un second appel
     * aux quatre portes qui déclenchent un cycle — une cinquième porte
     * l'aurait oublié.
     *
     * Le repère (`meta.saillances_vu`) évite la répétition : une grosse actu
     * reste saillante pendant des heures, la noter à chaque cycle remplirait la
     * chronologie du même titre toutes les soixante secondes. Au tout premier
     * passage on pose seulement le repère — sinon l'installation d'un démon sur
     * une base déjà remplie déverserait quarante lignes d'un coup.
     */
    private static function journaliserSaillances(Base $base): void
    {
        $maintenant = time();
        $repere = (int) ($base->meta('saillances_vu') ?? 0);

        if ($repere === 0) {
            $base->setMeta('saillances_vu', (string) $maintenant);

            return;
        }

        $vu = $repere;
        foreach (array_reverse($base->saillances($maintenant - 3600, 40)) as $s) {
            $quand = (int) $s['quand'];
            if ($quand <= $repere) {
                continue;
            }
            $vu = max($vu, $quand);

            $g = $s['groupe'] ?? [];
            [$niveau, $source, $message] = match ((string) $s['categorie']) {
                'actu' => [
                    (int) ($g['niveau'] ?? 0) >= Alerte::URGENT ? 'error' : 'warn',
                    'actu',
                    sprintf(
                        '%s ×%d : %s',
                        Alerte::nom((int) ($g['niveau'] ?? 0)),
                        (int) ($g['sources'] ?? 1),
                        Util::tronquer((string) ($g['titre'] ?? ''), 90),
                    ),
                ],
                'signal' => [
                    'info',
                    'signal faible',
                    sprintf(
                        'score %d : %s',
                        (int) ($g['score'] ?? 0),
                        Util::tronquer((string) ($g['titre'] ?? ''), 90),
                    ),
                ],
                'ia' => [
                    'info',
                    'second avis',
                    sprintf(
                        '%s → %s : %s',
                        Alerte::nom((int) ($g['niveau'] ?? 0)),
                        Alerte::nom((int) ($g['ia_niveau'] ?? 0)),
                        Util::tronquer((string) ($g['titre'] ?? ''), 80),
                    ),
                ],
                'pic' => ['warn', 'débit', 'pic : ' . Util::tronquer((string) ($s['texte'] ?? ''), 100)],
                'flux' => [
                    ($s['morte'] ?? false) ? 'error' : 'ok',
                    'flux',
                    sprintf('%s : source %s', (string) ($s['nom'] ?? ''), ($s['morte'] ?? false) ? 'morte' : 'rétablie'),
                ],
                default => ['info', 'collecte', ''],
            };

            if ($message !== '') {
                Journal::noter($niveau, $source, $message);
            }
        }

        if ($vu > $repere) {
            $base->setMeta('saillances_vu', (string) $vu);
        }
    }

    /**
     * La page.
     *
     * @param array<string, mixed>       $c     contexte rendu par contexte()
     * @param list<array<string, mixed>> $tours la conversation ouverte
     */
    public static function rendre(array $c, array $tours, ?array $ancre = null): string
    {
        $stats = $c['stats'];
        $maintenant = (int) $c['maintenant'];

        /* Le vhost met un mois de cache sur les .js et les .css : sans cette
           version, une correction resterait invisible jusque-là. Les trois
           fichiers comptent — une évolution de XOSHUI recopiée ici ne touche
           pas narh.js, et ne serait donc jamais servie si la version ne suivait
           que lui. */
        $v = NARH_VERSION . '.' . max(
            (int) @filemtime(NARH_RACINE . '/libs/js/narh.js'),
            (int) @filemtime(NARH_RACINE . '/libs/js/xoshui.js'),
            (int) @filemtime(NARH_RACINE . '/libs/css/xoshui.css'),
        );

        $surLigne = array_filter(self::COMMANDES, static fn (array $cmd): bool => $cmd[1] === true);
        $mortes = (int) ($stats['sources']['mortes'] ?? 0);
        $antenne = Direct::enAntenne();

        /* Le mode dépêche est un état : la prochaine question partira avec cette
           dépêche au dossier. Tout état doit pouvoir se quitter — sans cette
           croix, il fallait recharger l'adresse à la main pour revenir à une
           conversation ordinaire, et rien à l'écran ne le disait. */
        $bandeau = $ancre !== null ? Vue::ancre($ancre) : '';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NARH</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/libs/css/xoshui.css?v=<?= e($v) ?>">
</head>
<body>
<!-- Tout l'écran est en mode console : les composants ci-dessous sont ceux du
     framework, retraduits en plein caractère par la seule classe xo-console. -->
<!-- `height` et non `min-height` : la classe du framework laisse la colonne
     grandir au-delà de la vue, ce qui remet le défilement sur la page entière et
     emporte le champ vers le bas. Bornée à la hauteur de la vue, seule la
     conversation défile — l'en-tête, la barre d'état et le champ ne bougent plus. -->
<div class="xo-app xo-console" style="height: 100vh"
     data-phase="<?= e(NARH_PHASE) ?>"
     <?php /* Les commandes déclarées mais pas encore branchées, avec la phase
              qui les amènera. Le navigateur annonçait jusqu'ici la phase de
              l'application à leur place — « arrive après P5 » pour une commande
              qui déclarait P4, et déjà livrée. Une seule table les décide
              (COMMANDES), elle voyage donc telle quelle. */ ?>
     data-phases="<?= e((string) json_encode(array_map(
         static fn (array $cmd): string => $cmd[2],
         array_filter(self::COMMANDES, static fn (array $cmd): bool => $cmd[2] !== ''),
     ), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>"
     data-antenne="<?= $antenne ? '1' : '0' ?>"
     data-budget="<?= Direct::BUDGET ?>"
     data-fil="<?= Agent::filId() ?>"
     data-sondage="<?= (int) narh_reglage('sondage', 12) ?>"
     data-sondage-inactif="<?= (int) narh_reglage('sondage_inactif', 60) ?>"
     data-dernier-id="<?= (int) ($stats['dernier_id'] ?? 0) ?>">

  <!-- Le seul chrome permanent : une ligne. Elle dit l'état des deux organes —
       ce qui arrive tout seul, et ce qui répond quand on demande. -->
  <div class="xo-statusbar">
    <strong>NARH</strong>
    <span class="xo-statusbar__label">veille:</span>
    <span id="etat-sources" class="<?= $mortes > 0 ? 'xo-warning' : 'xo-success' ?>"><?= (int) ($stats['sources']['saines'] ?? 0) ?>/<?= (int) ($stats['sources']['total'] ?? 0) ?></span>
    <span class="xo-statusbar__label">1 h:</span>
    <span id="etat-h1"><?= (int) $stats['h1'] ?></span>
    <span class="xo-statusbar__label">cycle:</span>
    <span id="etat-cycle" class="xo-muted"><?= e(Util::duree((int) ($c['cycle']['ms'] ?? 0))) ?></span>
    <?= Icone::rendre('moteur') ?>
    <span class="xo-faint" id="etat-modele">—</span>
    <!-- Le régime n'est pas ici : il porte une étiquette de couleur dans la
         tuile Utilisateur (`Vue::regime()`), où il se voit sans se lire. Le
         répéter dans cette ligne, c'était deux endroits à tenir d'accord. -->
    <span class="xo-spacer"></span>
    <span class="xo-row">
      <span class="xo-spinner" id="temoin" aria-hidden="true"></span>
      <span class="xo-muted" id="etat-sondage">en écoute</span>
    </span>
    <span class="xo-faint" id="horloge"><?= e(date('H:i:s', $maintenant)) ?></span>
  </div>

  <!-- Trois bandes, et la page ne défile jamais : l'en-tête et le champ restent
       en place, seule la conversation défile en elle-même. Un écran où le champ
       s'échappe vers le bas oblige à faire défiler pour parler — c'est le geste
       le plus fréquent, il ne doit rien coûter. -->
  <main class="xo-main" style="display: flex; flex-direction: column; min-height: 0; overflow: hidden">

    <div style="max-width: 150ch; margin: 0 auto; width: 100%; display: flex; flex-direction: column; min-height: 0; flex: 1">

      <?php if ($c['amorce']): ?>
      <div class="xo-alert" role="status" style="margin-bottom: 8px">
        <span aria-hidden="true">i</span>
        <span class="xo-alert__body">
          <span class="xo-alert__title">Première collecte effectuée.</span>
          Pour un rafraîchissement qui ne dépende plus du navigateur, lancer
          <code>php cli.php --veille</code> dans un terminal.
        </span>
      </div>
      <?php endif; ?>

      <!-- Deux colonnes : la conversation aux deux tiers, le Newsdesk au
           tiers. Le poste de travail est **à côté** de ce qu'on lit, plus
           au-dessus : une bande horizontale volait de la hauteur au flux, alors
           que la largeur, elle, était perdue — un titre de dépêche fait cent
           caractères, pas cent cinquante. -->
      <!-- `grid-auto-rows` : une grille dimensionne sa rangée sur le contenu,
           même quand elle a elle-même une hauteur définie. Sans cette ligne, les
           deux colonnes s'arrêtaient à la hauteur du plus court des deux et
           laissaient un vide sous le Newsdesk. -->
      <div class="xo-grid" style="flex: 1; min-height: 0; grid-auto-rows: minmax(0, 1fr)">

        <div class="xo-col-8" style="display: flex; flex-direction: column; min-height: 0">

          <?= self::utilisateur($c) ?>

          <!-- Inspecté, entre l'utilisateur et le champ : ce qu'on vient de
               désigner, et ce qu'on peut en faire, juste avant l'endroit où on
               en parle. Absent tant qu'aucune ligne n'est choisie — il ne prend
               de la hauteur que quand il a quelque chose à montrer. -->
          <?= self::inspecte() ?>

          <!-- Le champ, sous l'utilisateur et **au-dessus** du flux : il ne
               bouge jamais, et ce qui vient d'arriver apparaît immédiatement
               dessous. En bas, il obligeait à faire défiler pour parler dès que
               la conversation dépassait une hauteur d'écran — le geste le plus
               fréquent était le plus coûteux. -->
          <!-- `id` : « Interroger l'agent dessus » y insère le bandeau sans
               recharger la page. Sans point d'ancrage nommé, il aurait fallu le
               deviner par sa position, qui bougerait au premier ajout. -->
          <div id="zone-champ" style="flex: none; margin: 16px 0">
            <?= $bandeau ?>
            <?= self::champ($ancre) ?>
          </div>

          <!-- Un seul flux, quel que soit le régime.
               Les segments de l'antenne et les tours de conversation s'y mêlent
               dans l'ordre où ils sont arrivés — c'est la même chronologie, et
               c'était le sens de la règle 7 depuis le début. -->
          <div class="xo-scroll" id="flux" style="flex: 1; min-height: 0; padding-right: 1ch">
            <ul class="xo-timeline" id="flux-liste"><?= Vue::tours($tours) ?></ul>

            <?php if ($tours === []): ?>
            <!-- L'accueil : ce qu'on peut faire, sous le champ qui le fera. Il
                 disparaît dès qu'une première chose arrive, segment compris. -->
            <div class="xo-state" id="accueil" style="--xo-min-h: 0; justify-content: flex-start">
              <p class="xo-state__title">Sur quoi devons-nous travailler ?</p>
              <p class="xo-state__msg">
                NARH surveille <?= (int) ($stats['sources']['total'] ?? 0) ?> sources en continu.
                Lui demander une actualité le fait chercher dans ce qu'il a collecté ; taper
                <code>/veille</code> ouvre le fil sans rien demander au modèle.
              </p>
              <div class="xo-row">
                <button class="xo-btn xo-btn--ghost" type="button" data-suggestion="/veille">Ouvrir la veille</button>
                <button class="xo-btn xo-btn--ghost" type="button" data-suggestion="Que dit la veille aujourd'hui ?">Que dit la veille ?</button>
                <button class="xo-btn xo-btn--ghost" type="button" data-suggestion="/alertes">Les alertes</button>
                <button class="xo-btn xo-btn--ghost" type="button" data-suggestion="/journal">Le journal</button>
              </div>
            </div>
            <?php endif; ?>
          </div>

        </div>

        <aside class="xo-col-4" style="display: flex; flex-direction: column; min-height: 0"
               aria-label="Newsdesk">
          <?= self::newsdesk($c) ?>
        </aside>

      </div>

    </div>
  </main>

  <div class="xo-keys">
    <span><kbd>Ctrl+K</kbd> commandes</span>
    <span><kbd>/</kbd> une commande dans le champ</span>
    <span><kbd>?</kbd> aide</span>
    <span class="xo-spacer"></span>
    <span class="xo-faint" id="etat-pied"><?= (int) $stats['articles'] ?> dépêches · <?= (int) $stats['groupes'] ?> événements</span>
    <span class="xo-faint">NARH <?= e(NARH_VERSION) ?> · <?= e(NARH_PHASE) ?></span>
  </div>

</div>

<!-- Le menu contextuel est déclaré une seule fois et sert toutes les tuiles :
     un menu par ligne multiplierait le balisage par trois cents pour un menu
     visible à la fois. narh.js répond à `xo:menu`, XOSHUI ne décide de rien. -->
<div class="xo-menu" id="menu-narh" role="menu" hidden>
  <div class="xo-menu__titre"></div>
  <?php foreach ($surLigne as $cle => [$texte, , $phase, , $icone]): ?>
  <button class="xo-menu__item" role="menuitem" type="button" data-action="<?= e($cle) ?>">
    <?= Icone::rendre($icone) ?> <?= e($texte) ?>
  </button>
  <?php endforeach; ?>
</div>

<div class="xo-toasts" id="toasts"></div>
<?= Vue::gabaritToast() ?>
<?= Vue::gabaritAttente() ?>

<!-- La palette n'est pas un raccourci vers des actions qui n'existeraient
     qu'elle : c'est une porte de plus vers les mêmes commandes. Sans barre
     latérale, c'est aussi la table des matières de l'application. -->
<dialog class="xo-palette" id="palette" data-xo-palette aria-label="Palette de commandes">
  <label class="xo-search">
    <span class="xo-search__prefix"><?= Icone::rendre('chercher') ?></span>
    <input type="text" placeholder="Tapez une commande…" aria-label="Commande">
  </label>
  <ul class="xo-palette__list xo-list" data-xo-list role="listbox">
    <?php $i = 0; foreach (self::COMMANDES as $cle => [$texte, , $phase, $groupe, $icone]): ?>
    <li class="xo-list__item" role="option" aria-selected="<?= $i++ === 0 ? 'true' : 'false' ?>"
        data-value="<?= e($cle) ?>">
      <?= Icone::rendre($icone) ?>
      <!-- Le libellé reste seul dans `xo-palette__label` : c'est là que XOSHUI
           insère le surlignage de la frappe, et une icône dedans serait
           parcourue par la recherche. -->
      <span class="xo-palette__label"><?= e($texte) ?></span>
      <span class="xo-list__meta"><?= e($groupe) ?><?= $phase !== '' ? ' · ' . e($phase) : '' ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="xo-palette__empty" hidden>Aucune commande ne correspond.</p>
  <div class="xo-keys"><span><kbd>↑↓</kbd> naviguer</span><span><kbd>Entrée</kbd> exécuter</span></div>
</dialog>

<!-- Les réglages : ce qui change le comportement de l'agent, et rien d'autre.
     Les chemins de base et les cadences restent dans config/reglages.php — ils
     doivent se lire sans PHP applicatif ni base (CLAUDE.md, § Ce qu'on ne
     recopie pas), et se tromper dessus depuis un écran couperait l'écran. -->
<dialog class="xo-dialog" id="reglages" aria-label="Réglages">
  <p class="xo-dialog__title">Réglages</p>
  <div class="xo-dialog__body">
    <?php $r = Agent::reglages(); ?>
    <label class="xo-field">
      <span class="xo-label">Nom affiché</span>
      <input class="xo-input" type="text" id="r-utilisateur"
             value="<?= e((string) narh_reglage('utilisateur', '')) ?>">
      <span class="xo-hint">Il donne aussi son motif à l'avatar.</span>
    </label>

    <label class="xo-field">
      <span class="xo-label">Modèle</span>
      <input class="xo-input" type="text" id="r-modele" value="<?= e((string) $r['modele']) ?>">
      <span class="xo-hint">Tel qu'Ollama le nomme, par exemple <code>llama3.2:3b</code>.</span>
    </label>

    <label class="xo-field">
      <span class="xo-label">Température</span>
      <input class="xo-input" type="text" id="r-temperature" value="<?= e((string) $r['temperature']) ?>">
      <span class="xo-hint">0 pour la réponse la plus attendue, 1 pour la plus libre.</span>
    </label>

    <label class="xo-check">
      <input type="checkbox" id="r-outils" <?= !empty($r['outils_auto']) ? 'checked' : '' ?>>
      <span>Proposer les outils au modèle</span>
    </label>
    <p class="xo-hint">Décoché, il ne cherche plus dans la veille de lui-même.</p>
  </div>
  <div class="xo-dialog__actions">
    <span class="xo-faint" id="r-etat"></span>
    <span class="xo-spacer"></span>
    <button class="xo-btn xo-btn--ghost" type="button" data-xo-close>Annuler</button>
    <button class="xo-btn xo-btn--ghost xo-accent" type="button" id="r-enregistrer">Enregistrer</button>
  </div>
</dialog>

<dialog class="xo-help" id="aide" data-xo-help aria-label="Aide">
  <p class="xo-help__title">Se servir de NARH</p>
  <dl class="xo-help__grid">
    <dt class="xo-help__group">Une seule lecture</dt>
    <dt>La conversation</dt><dd>Tout s'y passe : ce qu'on demande, et ce que ça donne.</dd>
    <dt>Une tuile</dt><dd>Un résultat encadré, posé dans le fil. Elle se refait à chaque lecture.</dd>

    <dt class="xo-help__group">Convoquer</dt>
    <dt><code>/veille</code></dt><dd>Le fil des événements collectés.</dd>
    <dt><code>/alertes</code></dt><dd>Ce qui dépasse le seuil, sur six heures.</dd>
    <dt><code>/journal</code></dt><dd>La collecte et l'agent, dans le même ordre.</dd>
    <dt><code>/memoire</code></dt><dd>Les fils passés. Un clic en rouvre un.</dd>
    <dt>Ctrl + K</dt><dd>Les mêmes commandes, filtrables.</dd>

    <dt class="xo-help__group">Sur une ligne de tuile</dt>
    <dt>Clic</dt><dd>La sélectionne.</dd>
    <dt>Clic droit</dt><dd>Suivre, traiter, écarter, ouvrir, interroger l'agent.</dd>
    <dt>Double-clic</dt><dd>Ouvre l'article dans un onglet.</dd>
    <dt>Teinte</dt><dd>Elle dit le marquage ; la pâleur dit l'âge.</dd>
  </dl>
</dialog>

<script type="module" src="/libs/js/xoshui.js?v=<?= e($v) ?>"></script>
<script type="module" src="/libs/js/narh.js?v=<?= e($v) ?>"></script>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * L'en-tête : des tuiles **fixes**, d'organisation.
     *
     * Elles ne racontent rien — elles situent. Ce qui doit rester sous les yeux
     * sans qu'on le demande : l'état de la collecte, ce qui dépasse un seuil, et
     * où l'on en était. Trois lignes chacune au plus : au-delà, ce serait un
     * panneau, et un panneau permanent occupe l'écran pour un contenu qu'on
     * regarde par intermittence — c'est exactement ce qu'on vient de retirer.
     *
     * La distinction avec les tuiles de la conversation tient en une phrase :
     * **ici on se repère, en dessous on travaille.** Celles-ci ne bougent pas,
     * celles-là s'accumulent en trace de ce qu'on a demandé.
     *
     * @param array<string, mixed> $c
     */
    private static function utilisateur(array $c): string
    {
        Direct::amorcer();

        /* -- Qui regarde --
           Les compteurs de collecte tenaient cette place ; ils disaient déjà
           leur affaire dans la barre d'état, et afficher deux fois le même
           chiffre à trente centimètres d'écart, c'est demander lequel croire.

           Hauteur libre mais contenu fixe : trois lignes et une rangée de
           boutons, rien qui puisse s'allonger — le champ en dessous ne bougera
           donc pas. */
        return '<section class="xo-panel xo-panel--pad" style="flex: none">'
            /* Pas d'icône dans un titre de panneau : en mode console, le titre
               interrompt le filet (`──┤ Titre ├──`), et y glisser un glyphe de
               plus alourdit une bordure au lieu de nommer une section. Les
               icônes restent sur les boutons, où elles désignent une action. */
            . '<h2 class="xo-panel__title">Utilisateur</h2>'
            . Vue::utilisateur(
                (string) narh_reglage('utilisateur', 'vous'),
                Memoire::bilan(),
                Direct::enAntenne(),
            )
            /* Les gestes qui appartiennent à celui qui regarde : ouvrir un fil
               neuf, retrouver les anciens, régler l'agent. Le fil de veille,
               lui, est au Newsdesk — c'est le poste, pas la personne. */
            . '<div class="xo-row" style="margin-top: 8px">'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="fil-neuf">'
            . Icone::rendre('fil-neuf') . ' Fil neuf</button>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="memoire">'
            . Icone::rendre('memoire') . ' Mémoire</button>'
            . '<span class="xo-spacer"></span>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-xo-open="#reglages">'
            . Icone::rendre('reglages') . ' Réglages</button>'
            . '</div>'
            . '</section>';
    }

    /**
     * La zone d'inspection — ce qu'on regarde, et ce qu'on peut en faire.
     *
     * Elle vivait en tête du Newsdesk, dans la colonne de droite. Elle est
     * passée **entre l'utilisateur et le champ**, pour une raison de lecture :
     * le Newsdesk parle de *listes* — ce qu'on a marqué, ce qui arrive, les
     * outils — alors qu'Inspecté parle d'**un** objet, celui qu'on vient de
     * choisir. Le laisser au milieu des listes obligeait l'œil à traverser la
     * colonne pour relier une ligne cliquée à gauche à son détail à droite.
     *
     * Ici, elle forme avec le champ une seule bande : on regarde ce qu'on a
     * désigné, on agit dessus ou on en parle, puis on lit la conversation. Les
     * trois gestes sont dans l'ordre où on les fait.
     *
     * **Elle n'existe que lorsqu'une ligne est choisie.** Permanente, elle
     * volerait de la hauteur à la conversation pour un contenu qu'on regarde
     * par intermittence — c'est exactement le reproche que `CLAUDE.md` fait aux
     * panneaux, et le champ ne doit jamais descendre hors de l'écran. `narh.js`
     * la découvre en même temps que les gestes, sur la même sélection.
     *
     * Les actions restent **avec** l'objet qu'elles visent, pas dans une barre
     * à part : séparées, il fallait regarder à un endroit pour savoir sur quoi
     * on allait agir à un autre. Elles passent par `commander()` comme tout le
     * reste — une porte de plus vers les mêmes commandes, jamais un raccourci
     * (règle 5).
     */
    private static function inspecte(): string
    {
        /* Les gestes qui visent une ligne, dans l'ordre où un desk les
           enchaîne : on regarde, on décide, on ouvre, on interroge. */
        $gestes = '';
        foreach (self::COMMANDES as $cle => [$texte, $surLigne, $phase, , $icone, $court]) {
            if (!$surLigne) {
                continue;
            }
            /* Le verbe est écrit, pas seulement dessiné : une rangée d'icônes
               muettes oblige à survoler chacune pour savoir ce qu'elle fait, et
               en mode console les crochets `[ suivre ]` coûtent quatre
               caractères de plus pour un geste qu'on lit du premier coup. */
            $gestes .= '<button class="xo-btn xo-btn--ghost" type="button" data-action="' . e($cle) . '"'
                . ' data-xo-tip="' . e($texte) . '">'
                . Icone::rendre($icone) . ' ' . e($court) . '</button>';
        }

        /* `max-height` en clair, pas `--xo-max-h` : le token n'est lu que par
           `xo-panel__body`, `xo-log`, `xo-editor`, `xo-dialog__body` et
           `xo-table-wrap`. Posé sur `xo-scroll`, qui n'est qu'un
           `overflow: auto`, il ne bornerait rien — le détail d'une dépêche avec
           son résumé et sa fratrie pousserait le champ hors de l'écran.

           Plus bas qu'au Newsdesk (16vh contre 20) : la colonne fait ici les
           deux tiers de la largeur, le même texte y tient en moins de lignes,
           et ce qui est gagné revient à la conversation. */
        return '<section class="xo-panel xo-panel--pad" id="inspection"'
            . ' style="flex: none; margin-top: 8px" aria-label="Inspecté" hidden>'
            . '<div class="xo-rule xo-rule--start">Inspecté</div>'
            . '<div class="xo-scroll" id="desk-inspecte" style="max-height: 16vh">'
            . Vue::inspecteur(null) . '</div>'
            . '<div class="xo-row" id="desk-gestes" style="margin-top: 8px" hidden>'
            . '<div class="xo-btn-group" role="group" aria-label="Gestes de desk">' . $gestes . '</div>'
            . '</div>'
            . '</section>';
    }

    /**
     * Le Newsdesk — le poste de travail.
     *
     * Trois zones de **listes**, et leur ordre est celui du geste : on trie ce
     * qu'on a marqué, on cherche la suivante, on outille. Ce qui parlait d'un
     * seul objet — Inspecté — est passé à gauche, contre le champ.
     *
     * | Zone | Fixe ? |
     * |---|---|
     * | **Suivis · Traités · Écartés** — ce qu'on a marqué | onglets |
     * | **Alertes et veille** — ce qui arrive, alertes d'abord | fixe |
     * | **Outils** — le poste de commande | en bas |
     *
     * @param array<string, mixed> $c contexte rendu par contexte()
     */
    private static function newsdesk(array $c): string
    {
        $antenne = Direct::enAntenne();
        $alertes = $c['alertes'];
        $appels = $c['appels'];
        $stats = $c['stats'];
        $statuts = $c['statuts'];

        /* Colonne flexible : les onglets prennent la hauteur qui reste et
           défilent en eux-mêmes. Sans cela, un onglet plus long que les autres
           débordait de la bande au lieu d'y tenir. */
        return '<section class="xo-panel xo-panel--pad"'
            . ' style="display: flex; flex-direction: column; flex: 1; min-height: 0">'
            . '<h2 class="xo-panel__title">Newsdesk</h2>'

            /* -- Ce qu'on a marqué : un onglet par état --
               Trois onglets et non un filtre : ce sont trois décisions
               différentes, et leur compte doit se lire sans cliquer. Plus
               d'onglet Antenne — le régime se bascule d'un clic sur son
               étiquette, dans la tuile Utilisateur. */
            . '<div class="xo-tabs" data-xo-tabs role="tablist"'
            . ' style="flex-wrap: wrap; overflow-x: visible">'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="true" aria-controls="onglet-suivis"'
            . ' data-rafraichir="suivis">Suivis <span data-compte="suivi">'
            . (int) $statuts['suivi'] . '</span></button>'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="false" aria-controls="onglet-traites"'
            . ' data-rafraichir="traites">Traités <span data-compte="traite">'
            . (int) $statuts['traite'] . '</span></button>'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="false" aria-controls="onglet-ecartes"'
            . ' data-rafraichir="ecartes">Écartés <span data-compte="ecarte">'
            . (int) $statuts['ecarte'] . '</span></button>'
            . '</div>'

            . '<section id="onglet-suivis" role="tabpanel" class="xo-tabpanel xo-scroll"'
            . ' style="flex: 1; min-height: 0">'
            . '<div id="desk-suivis">' . Vue::lignesEvenements($c['suivis']) . '</div>'
            . '</section>'

            . '<section id="onglet-traites" role="tabpanel" class="xo-tabpanel xo-scroll"'
            . ' style="flex: 1; min-height: 0" hidden>'
            . '<div id="desk-traites">' . Vue::lignesEvenements($c['traites']) . '</div>'
            . '</section>'

            . '<section id="onglet-ecartes" role="tabpanel" class="xo-tabpanel xo-scroll"'
            . ' style="flex: 1; min-height: 0" hidden>'
            . '<div id="desk-ecartes">' . Vue::lignesEvenements($c['ecartes']) . '</div>'
            . '</section>'

            /* -- Ce qui arrive : alertes puis veille, une seule liste --
               Deux listes séparées obligeaient à choisir laquelle regarder alors
               qu'une alerte **est** un événement de la veille, simplement passé
               au-dessus du seuil. Réunies et ordonnées — le grave d'abord — on
               lit une seule colonne du haut vers le bas. */
            . '<div style="flex: none; margin-top: 8px">'
            . '<div class="xo-rule xo-rule--start">Alertes et veille</div>'
            . '<div class="xo-row" style="margin-bottom: 8px">'
            . '<label class="xo-search" style="flex: 1 1 12ch; min-width: 8ch">'
            . '<span class="xo-search__prefix" aria-hidden="true">/</span>'
            . '<input type="search" id="desk-q" aria-label="Chercher dans la veille"'
            . ' placeholder="chercher…"></label>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="veille"'
            . ' data-xo-tip="Poser le fil en tuile">' . Icone::rendre('veille') . '</button>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="relever"'
            . ' data-xo-tip="Relever maintenant">' . Icone::rendre('relever') . '</button>'
            . '</div>'
            . '<div class="xo-scroll" id="desk-veille" style="max-height: 22vh">'
            . Vue::lignesEvenements(self::alertesPuisVeille($alertes, $c['recents']))
            . '</div>'
            . '</div>'

            /* Les outils au pied de la colonne, hors des onglets.
               Ce n'est pas un état qu'on consulte mais un poste de commande :
               on veut pouvoir lancer une recherche pendant qu'on regarde les
               alertes, sans changer d'onglet et perdre ce qu'on lisait. Il ne
               défile pas et ne bouge pas — le reste de la colonne s'ajuste. */
            . '<div style="flex: none; margin-top: 8px">'
            . '<div class="xo-rule xo-rule--start">Outils <span id="desk-outils-compte">'
            . count($appels) . '</span></div>'
            . '<div id="desk-outils">' . Vue::outils($appels) . '</div>'
            . Vue::formulaireOutil()
            . '</div>'

            . '</section>';
    }

    /**
    /**
     * Les alertes en tête, puis la veille — sans doublon.
     *
     * Une alerte **est** un événement de la veille, simplement passé au-dessus
     * du seuil : elle figure donc dans les deux listes. Les concaténer sans
     * dédoublonner l'aurait affichée deux fois, une fois en haut et une fois à
     * sa place chronologique, et on aurait cru à deux faits.
     *
     * @param list<array<string, mixed>> $alertes
     * @param list<array<string, mixed>> $recents
     * @return list<array<string, mixed>>
     */
    public static function alertesPuisVeille(array $alertes, array $recents): array
    {
        $vus = [];
        $liste = [];

        foreach ([...$alertes, ...$recents] as $g) {
            $id = (int) $g['id'];
            if (isset($vus[$id])) {
                continue;
            }
            $vus[$id] = true;
            $liste[] = $g;
        }

        return $liste;
    }

    /**
     * Le champ — la seule entrée de l'application.
     *
     * Il accepte deux choses sans les distinguer à l'œil : une question pour le
     * modèle, et une commande (`/veille`). C'est voulu — obliger à choisir un
     * mode avant de taper, c'est demander de savoir avant de commencer.
     */
    private static function champ(?array $ancre): string
    {
        return '<label class="xo-prompt">'
            . '<span class="xo-prompt__sign">' . Icone::rendre('invite') . '</span>'
            . '<input type="text" id="chat-saisie" aria-label="Demande"'
            . ' data-depeche="' . ($ancre !== null ? (int) $ancre['id'] : '') . '"'
            . ' placeholder="' . ($ancre !== null ? 'Que demander sur cette dépêche ?' : 'Demandez, ou tapez /veille…') . '"'
            . ' autofocus>'
            . '</label>'
            . '<div class="xo-row" style="margin-top: 8px">'
            /* La petite zone d'attente des gestes courts — ouvrir une tuile,
               marquer une ligne. Ceux-là n'ont pas de place dans le flux :
               sans elle, un clic restait sans réponse visible le temps de
               l'aller-retour. Les réponses du modèle, elles, patientent dans
               leur propre ligne (voir `Vue::gabaritAttente()`). */
            . '<span class="xo-row" id="chat-phase" hidden>'
            . '<span class="xo-spinner" aria-hidden="true"></span>'
            . '<span class="xo-faint" data-dit></span>'
            . '</span>'
            . '<span class="xo-spacer"></span>'
            . '<span class="xo-faint">Entrée pour envoyer</span>'
            . '</div>';
    }
}
