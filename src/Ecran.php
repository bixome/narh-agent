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
     * `natures` dit **sur quoi** la commande agit — vide si elle ne vise aucune
     * ligne. Ce fut longtemps un simple booléen « surLigne », et c'était la
     * cause d'une famille entière de défauts : les huit gestes s'affichaient dès
     * qu'une ligne était choisie, quelle qu'elle soit, alors que cinq natures
     * différentes sont sélectionnables (dépêche, événement, fil, fait, passage).
     *
     * Les conséquences se lisaient mal parce qu'elles ne levaient rien :
     * `suivre` sur une ligne de **fil** envoyait l'identifiant du fil à une
     * route qui attend celui d'une dépêche, résolvait son groupe et marquait un
     * sujet sans rapport — une corruption silencieuse, pas une erreur. Et
     * `oublier`, seul geste de conversation au milieu de sept gestes de veille,
     * proposait de supprimer un fil sur une dépêche.
     *
     * Déclarer les natures ici les fait respecter partout d'un coup : la barre
     * de gestes, le menu contextuel et le repli de `commander()` lisent la même
     * table. La phase, quand elle est là, dit que la commande n'est pas branchée
     * — l'écran doit le dire au lieu de faire semblant.
     *
     * L'icône est celle du **rôle**, pas du dessin (voir `tools/icones.php`) :
     * une commande la porte partout où elle apparaît, palette et clic droit
     * compris. Déclarée ici, elle ne peut pas diverger d'une porte à l'autre.
     *
     * `court` est le verbe seul, pour la barre contextuelle : « Suivre
     * l'événement » y répéterait « l'événement » sept fois de suite, alors que
     * la ligne visée est nommée juste à côté.
     *
     * `auto` dit qu'une **conduite** peut jouer la commande sans écran (règle 6,
     * et `src/Conduite.php`). Ce n'est pas une permission mais une capacité :
     * « poser une tuile » ou « basculer l'antenne » n'ont aucun sens dans un
     * démon, qui n'a ni conversation ouverte ni navigateur. Le champ est donc
     * rempli pour les quatre commandes qui agissent en base et pour elles
     * seules — le déclarer ici, comme `natures`, évite d'avoir à le redécider
     * dans `config/conduites.php`, où il divergerait au premier ajout.
     *
     * clé => [libellé, natures, phase, groupe, icône, court, auto]
     */
    public const COMMANDES = [
        'direct'       => ['Passer en agent en direct',  '',                  '', 'Régime', 'direct', '', ''],
        'conversation' => ['Revenir en conversation',    '',                  '', 'Régime', 'antenne-fin', '', ''],
        'veille'       => ['Ouvrir la veille',           '',                  '', 'Tuiles', 'veille', '', ''],
        'alertes'      => ['Voir les alertes',           '',                  '', 'Tuiles', 'alerte', '', ''],
        'journal'      => ['Voir le journal',            '',                  '', 'Tuiles', 'journal', '', ''],
        'conduites'    => ['Voir ce qui se déclenche',   '',                  '', 'Tuiles', 'conduite', '', ''],
        'memoire'      => ['Voir les fils passés',       '',                  '', 'Tuiles', 'memoire', '', ''],
        'corpus'       => ['Chercher dans le corpus',    '',                  '', 'Tuiles', 'chercher', '', ''],
        'outils'       => ['Voir les outils du fil',     '',                  '', 'Tuiles', 'outils', '', ''],
        /* L'aide n'était atteignable que par `?`, et `?` ne part jamais depuis
           le champ — qui a le focus au chargement, où l'on tape, et qui écrit
           donc « ? » au lieu d'ouvrir quoi que ce soit. Le pied promettait un
           geste que l'état par défaut de l'écran rendait impossible.

           Ni raccourci maison ni bouton de plus : une **commande**, comme
           tout le reste (règle 6). Elle passe par `commander()`, donc par le
           champ, la palette et le clic droit à la fois — et `?` continue de
           marcher partout ailleurs, puisqu'il vient de XOSHUI. */
        'aide'         => ["Ouvrir l'aide",              '',                  '', 'Écran', 'aide', '', ''],
        'inspecter'    => ['Inspecter la ligne',         'depeche evenement', '', 'Tuiles', 'inspecter', 'inspecter', ''],
        'suivi'        => ["Suivre l'événement",         'depeche evenement', '', 'Veille', 'suivre', 'suivre', 'marque le groupe suivi'],
        'traite'       => ['Marquer traité',             'depeche evenement', '', 'Veille', 'traite', 'traité', 'marque le groupe traité'],
        'ecarte'       => ['Écarter',                    'depeche evenement', '', 'Veille', 'ecarter', 'écarter', 'écarte le groupe du desk'],
        'lire'         => ['Lire le texte ici',          'depeche evenement', '', 'Veille', 'lire', 'lire', ''],
        // `passage` aussi : un extrait de corpus porte le lien de son article,
        // et l'ouvrir marchait déjà — par accident. Ici c'est déclaré.
        'ouvrir'       => ["Ouvrir l'article",           'depeche evenement passage', '', 'Veille', 'ouvrir', 'ouvrir', ''],
        // Seule commande `auto` qui coûte des secondes de modèle : `Conduite`
        // la réserve au démon pour cette raison, pas parce qu'elle serait
        // moins légitime que les trois autres.
        'interroger'   => ["Interroger l'agent dessus",  'depeche evenement', '', 'Veille', 'interroger', 'demander', "demande un briefing à l'agent"],
        'relever'      => ['Relever maintenant',         '',                  '', 'Veille', 'relever', '', ''],
        'desancrer'    => ['Oublier la dépêche visée',   '',                  '', 'Conversation', 'ecarter', '', ''],
        'fil-neuf'     => ['Ouvrir un fil neuf',         '',                  '', 'Conversation', 'fil-neuf', '', ''],
        'oublier'      => ['Oublier le fil',             'fil',               '', 'Conversation', 'oublier', 'oublier', ''],
        'quart'        => ['Écrire la note de quart',    '',                  '', 'Conversation', 'journal', '', ''],
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
     * Les gestes qu'une ligne de veille propose, tels qu'elle les nomme.
     *
     * `COMMANDES` en est la seule source, si bien que le menu contextuel, la
     * barre de gestes et l'aide décrivent forcément le même écran. La phrase
     * était écrite à la main : elle annonçait cinq gestes quand le menu en
     * offrait sept, « inspecter » et « lire » s'étant ajoutés sans elle.
     *
     * On prend le libellé **court** — celui que la barre de gestes affiche —
     * plutôt que le long : l'aide doit nommer ce qu'on lit à l'écran, pas une
     * autre formulation de la même chose.
     */
    private static function gestesDeLigne(): string
    {
        $verbes = [];
        foreach (self::COMMANDES as [, $natures, , , , $court]) {
            if ($court !== '' && str_contains($natures, 'evenement')) {
                $verbes[] = $court;
            }
        }

        return ucfirst(implode(', ', $verbes));
    }

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

            /* Après le cycle et non dedans : la collecte ne pense pas (règle 4),
               le collecteur rend un rapport et ignore ce qu'on en fait — c'est
               ce qui permet de l'appeler d'ici, du CLI ou de l'API. Ici comme
               pour le journal, et pour la même raison.

               Depuis l'écran aussi, et pas seulement depuis le démon : sans
               démon, l'écran est le seul endroit où un cycle a lieu, et des
               conduites qui ne tireraient jamais dans cette configuration
               seraient un réglage qu'on croit armé. Celles qui coûtent des
               secondes de modèle se réservent elles-mêmes (Conduite). */
            Conduite::evaluer($base, 'écran', time());

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

        $surLigne = array_filter(self::COMMANDES, static fn (array $cmd): bool => $cmd[1] !== '');
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
     <?php /* Les commandes déclarées mais pas encore branchées, avec la phase
              qui les amènera. Le navigateur annonçait jadis la phase de
              l'**application** à leur place — « arrive après P5 » pour une
              commande qui déclarait P4, et déjà livrée. Une seule table les
              décide (COMMANDES), elle voyage donc telle quelle. La table est
              vide aujourd'hui : tout est branché. */ ?>
     data-phases="<?= e((string) json_encode(array_map(
         static fn (array $cmd): string => $cmd[2],
         array_filter(self::COMMANDES, static fn (array $cmd): bool => $cmd[2] !== ''),
     ), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>"
     <?php /* Sur quoi chaque commande agit. La barre et le menu filtrent déjà
              par `data-natures`, mais la palette et le champ n'ont pas d'élément
              porteur : sans cette table, `/oublier` tapé au champ pendant qu'une
              dépêche est choisie repartait vers la suppression d'un fil. */ ?>
     data-natures="<?= e((string) json_encode(array_map(
         static fn (array $cmd): string => $cmd[1],
         array_filter(self::COMMANDES, static fn (array $cmd): bool => $cmd[1] !== ''),
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
            <?= self::champ($ancre, count($c['appels'])) ?>
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
    <!-- Les deux portes, et dans cet ordre : `?` ne part pas depuis le champ,
         qui a le focus au chargement. Celle qui marche toujours d'abord. -->
    <span><kbd>/aide</kbd> ou <kbd>?</kbd> hors du champ</span>
    <span class="xo-spacer"></span>
    <span class="xo-faint" id="etat-pied"><?= (int) $stats['articles'] ?> dépêches · <?= (int) $stats['groupes'] ?> événements</span>
    <span class="xo-faint">NARH <?= e(NARH_VERSION) ?></span>
  </div>

</div>

<!-- Le menu contextuel est déclaré une seule fois et sert toutes les tuiles :
     un menu par ligne multiplierait le balisage par trois cents pour un menu
     visible à la fois. narh.js répond à `xo:menu`, XOSHUI ne décide de rien. -->
<div class="xo-menu" id="menu-narh" role="menu" hidden>
  <div class="xo-menu__titre"></div>
  <?php /* `data-natures` : le menu est déclaré une seule fois pour tout
           l'écran, mais il s'ouvre sur cinq natures de ligne différentes. Sans
           ce filtre, il proposait « suivre » sur un fil de conversation et
           « oublier » sur une dépêche. `narh.js` masque au moment de l'ouvrir —
           le serveur ne peut pas savoir sur quoi on va cliquer. */ ?>
  <?php foreach ($surLigne as $cle => [$texte, $natures, $phase, , $icone]): ?>
  <button class="xo-menu__item" role="menuitem" type="button" data-action="<?= e($cle) ?>"
          data-natures="<?= e($natures) ?>">
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

    <?php
      /* Le catalogue est lu ici, au rendu de la page, et non à l'ouverture du
         dialogue : `api/fils.php` interroge déjà le moteur à chaque sondage,
         un appel de plus au chargement ne change pas la nature de la page. Le
         peupler depuis le navigateur aurait demandé au JS de dessiner des
         <option> — ce que la règle 2 lui interdit.

         Un champ libre laissait entrer une faute de frappe qui ne se voyait
         qu'au tour suivant, en « model not found » au milieu d'une réponse. */
      $catalogue = (new Ollama((string) narh_reglage('ollama')['url']))->catalogue();
      $courant   = (string) $r['modele'];
      $connu     = false;
      foreach ($catalogue as $m) {
          $connu = $connu || $m['nom'] === $courant;
      }
    ?>
    <label class="xo-field">
      <span class="xo-label">Modèle</span>
      <select class="xo-select" id="r-modele">
        <?php if (!$connu): ?>
          <!-- Le choix enregistré reste présent et sélectionné même quand le
               moteur ne le propose pas — injoignable, ou modèle désinstallé.
               Sans cette option, ouvrir les réglages pour corriger une
               température aurait changé la voix au passage, sans le dire. -->
          <option value="<?= e($courant) ?>" selected>
            <?= e($courant) ?><?= $catalogue === [] ? '' : ' · absent du moteur' ?>
          </option>
        <?php endif; ?>
        <?php foreach ($catalogue as $m): ?>
          <?php
            $etiquette = $m['nom'] . ' · ' . $m['parametres'] . ' · ' . $m['quantification']
                . ' · ' . number_format($m['taille'] / 1073741824, 1, ',', ' ') . ' Gio'
                . ($m['reflexion'] ? ' · réflexion' : '');
          ?>
          <option value="<?= e($m['nom']) ?>"<?= $m['nom'] === $courant ? ' selected' : '' ?>><?= e($etiquette) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="xo-hint">
        <?php if ($catalogue === []): ?>
          Moteur injoignable : seul le choix enregistré est proposé.
        <?php else: ?>
          Ce qu'Ollama a installé. La taille est celle du poids seul — la fenêtre
          de contexte s'ajoute par-dessus dans la VRAM.
        <?php endif; ?>
      </span>
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
    <!-- Sans l'article : « La conversation » était le seul terme trop long pour
         la colonne de `xo-help__grid`, qui aligne à droite. Il se coupait en
         deux — « La » en fin de colonne, « conversation » rejeté à la ligne
         suivante, à gauche, hors de la grille que tous les autres respectent.
         Le mot seul suffit à nommer, et il tient. -->
    <dt>Conversation</dt><dd>Tout s'y passe : ce qu'on demande, et ce que ça donne.</dd>
    <dt>Une tuile</dt><dd>Un résultat encadré, posé dans le fil. Elle se refait à chaque lecture.</dd>

    <dt class="xo-help__group">Convoquer</dt>
    <dt><code>/veille</code></dt><dd>Le fil des événements collectés.</dd>
    <dt><code>/alertes</code></dt><dd>Ce qui dépasse le seuil, sur six heures.</dd>
    <dt><code>/journal</code></dt><dd>La collecte et l'agent, dans le même ordre.</dd>
    <dt><code>/memoire</code></dt><dd>Les fils passés. Un clic en rouvre un.</dd>
    <dt>Ctrl + K</dt><dd>Les mêmes commandes, filtrables.</dd>

    <dt class="xo-help__group">Sur une ligne de tuile</dt>
    <dt>Clic</dt><dd>La sélectionne.</dd>
    <!-- Énumérée depuis COMMANDES et non à la main : la liste écrite disait
         « suivre, traiter, écarter, ouvrir, interroger » quand le menu en
         proposait sept — « inspecter » et « lire » s'étaient ajoutés sans
         qu'elle bouge. Une aide qui décrit un écran d'avant est pire que pas
         d'aide, et rien ne signale qu'elle a vieilli. -->
    <dt>Clic droit</dt><dd><?= e(self::gestesDeLigne()) ?>.</dd>
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
            /* `Corpus::etat()` compte quatre tables, dont une FTS5 : mesuré à
               4,2 ms une fois la base ouverte — elle l'est déjà quand la tuile
               se rend. Les 111 ms d'un premier appel à froid sont l'ouverture,
               que la page paie de toute façon. */
            . Vue::utilisateur(
                (string) narh_reglage('utilisateur', 'vous'),
                Memoire::bilan(),
                Direct::enAntenne(),
                Corpus::etat(),
            )
            /* Ne reste ici que ce qui vise **la personne**.

               « Fil neuf » et « Mémoire » ont rejoint la rangée du champ :
               ils parlent du fil, et le fil se tient en dessous. Les laisser
               dans une tuile qui sert à se repérer, c'était mettre des gestes
               de travail dans la bande où l'on ne travaille pas — et il
               fallait remonter en haut de l'écran pour changer de fil, alors
               qu'on écrit en bas. Les réglages restent : ils règlent l'agent,
               pas la conversation en cours. */
            . '<div class="xo-row" style="margin-top: 8px">'
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
        foreach (self::COMMANDES as $cle => [$texte, $natures, $phase, , $icone, $court]) {
            if ($natures === '') {
                continue;
            }
            /* Le verbe est écrit, pas seulement dessiné : une rangée d'icônes
               muettes oblige à survoler chacune pour savoir ce qu'elle fait, et
               en mode console les crochets `[ suivre ]` coûtent quatre
               caractères de plus pour un geste qu'on lit du premier coup. */
            $gestes .= '<button class="xo-btn xo-btn--ghost" type="button" data-action="' . e($cle) . '"'
                . ' data-natures="' . e($natures) . '"'
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
            /* Fermable, contrairement à ce que cette classe soutenait jusqu'ici.
               L'argument était qu'une zone escamotable ferait sauter le champ
               sous le curseur — c'est vrai, et c'est le prix retenu : à
               l'usage, garder un objet inspecté à l'écran longtemps après
               l'avoir traité coûte plus de hauteur, en permanence, que le saut
               ne coûte une fois.

               Le bouton ne passe pas par `commander()` : la règle 5 vise les
               **actions** — celles qu'on journalise, qu'on rejoue, qu'un seuil
               peut déclencher. Refermer un panneau est de l'état d'affichage,
               comme faire défiler ; en faire une commande nommée mettrait du
               bruit dans la chronologie unique. */
            . '<div class="xo-row">'
            . '<div class="xo-rule xo-rule--start" style="flex: 1">Inspecté</div>'
            . '<button class="xo-btn xo-btn--ghost" type="button" id="desk-inspecte-fermer"'
            . ' data-xo-tip="Fermer l\'inspection" aria-label="Fermer l\'inspection">×</button>'
            . '</div>'
            . '<div class="xo-scroll" id="desk-inspecte" style="max-height: 16vh">'
            . Vue::inspecteur(null) . '</div>'
            /* `xo-row` et non `xo-btn-group` : le groupe est un composant
               *segmenté*, XOSHUI colle ses boutons exprès (`margin-left: -1px`,
               et `0` en console) pour qu'ils se lisent comme un seul contrôle à
               choix unique. Ce n'en est pas un — ce sont huit gestes
               indépendants, et les coller demandait de viser. `xo-row` porte le
               `gap` du framework, sans une ligne de style à écrire. */
            . '<div class="xo-row" id="desk-gestes" role="group"'
            . ' aria-label="Gestes de desk" style="margin-top: 8px" hidden>'
            . $gestes
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
        $stats = $c['stats'];
        $statuts = $c['statuts'];

        /* Colonne flexible : les onglets prennent la hauteur qui reste et
           défilent en eux-mêmes. Sans cela, un onglet plus long que les autres
           débordait de la bande au lieu d'y tenir. */
        return '<section class="xo-panel xo-panel--pad"'
            . ' style="display: flex; flex-direction: column; flex: 1; min-height: 0">'
            . '<h2 class="xo-panel__title">Newsdesk</h2>'

            /* -- La recherche, au-dessus des onglets et non dedans --
               Elle porte sur la veille, mais la garder dans un seul onglet
               obligeait à y revenir pour chercher. En tête de colonne, elle
               commande la liste qu'on regarde. */
            . '<div class="xo-row" style="flex: none; margin-bottom: 8px">'
            . '<label class="xo-search" style="flex: 1 1 12ch; min-width: 8ch">'
            . '<span class="xo-search__prefix" aria-hidden="true">/</span>'
            . '<input type="search" id="desk-q" aria-label="Chercher dans la veille"'
            . ' placeholder="chercher…"></label>'
            /* Les deux seuls boutons de l'écran dont l'icône soit toute la
               parole — le reste des gestes écrit son verbe à côté. Ils gardent
               leur `aria-label` faute de pouvoir l'écrire : sans lui, un
               lecteur d'écran annonce « bouton », deux fois, et l'infobulle ne
               le rattrape pas puisqu'elle demande une souris. Le bouton de
               fermeture de l'inspection fait déjà les deux. */
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="veille"'
            . ' data-xo-tip="Poser le fil en tuile" aria-label="Poser le fil en tuile">'
            . Icone::rendre('veille') . '</button>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="relever"'
            . ' data-xo-tip="Relever maintenant" aria-label="Relever maintenant">'
            . Icone::rendre('relever') . '</button>'
            . '</div>'

            /* -- Quatre onglets : ce qui arrive, puis ce qu'on en a fait --
               « Alertes et veille » était un second panneau sous les onglets,
               avec son titre et sa hauteur à lui. Deux grammaires dans une
               colonne de 289 px : les deux listes se partageaient la hauteur,
               et aucune n'avait la place de montrer un titre — « Qua… ».
               Réunies, une seule liste occupe la colonne entière.

               L'ordre est une progression, pas un classement : ce qui arrive
               d'abord, les décisions qu'on en tire ensuite. Veille est donc
               l'onglet ouvert — on regarde un desk pour voir ce qui tombe,
               pas pour relire ce qu'on a déjà traité. */
            . '<div class="xo-tabs" data-xo-tabs role="tablist"'
            . ' style="flex-wrap: wrap; overflow-x: visible; flex: none">'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="true" aria-controls="onglet-veille"'
            . ' data-rafraichir="veille">Veille</button>'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="false" aria-controls="onglet-suivis"'
            . ' data-rafraichir="suivis">Suivis <span data-compte="suivi">'
            . (int) $statuts['suivi'] . '</span></button>'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="false" aria-controls="onglet-traites"'
            . ' data-rafraichir="traites">Traités <span data-compte="traite">'
            . (int) $statuts['traite'] . '</span></button>'
            . '<button class="xo-tabs__tab" role="tab" aria-selected="false" aria-controls="onglet-ecartes"'
            . ' data-rafraichir="ecartes">Écartés <span data-compte="ecarte">'
            . (int) $statuts['ecarte'] . '</span></button>'
            . '</div>'

            /* Une alerte **est** un événement de la veille, simplement passé
               au-dessus du seuil : les deux dans une seule liste, le grave
               d'abord, et l'on lit une colonne du haut vers le bas. */
            . '<section id="onglet-veille" role="tabpanel" class="xo-tabpanel xo-scroll"'
            . ' style="flex: 1; min-height: 0">'
            . '<div id="desk-veille">'
            . Vue::lignesEvenements(self::alertesPuisVeille($alertes, $c['recents']))
            . '</div>'
            . '</section>'

            . '<section id="onglet-suivis" role="tabpanel" class="xo-tabpanel xo-scroll"'
            . ' style="flex: 1; min-height: 0" hidden>'
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

            /* Les outils ont quitté ce pied de colonne pour la conversation.
               Ils disaient « aucun outil appelé **dans ce fil** » depuis la
               colonne de la veille : l'état d'un fil, affiché là où l'on suit
               ce qui arrive. Ils sont maintenant une tuile, convoquée depuis
               la rangée du champ, avec « Fil neuf » et « Mémoire » — les
               trois gestes qui parlent du fil, réunis là où le fil se tient. */

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
    private static function champ(?array $ancre, int $appels = 0): string
    {
        /* Le champ porte un fond : nu, il se confondait avec le fil des tours,
           alors qu'il est la seule entrée de l'application. `--raise`, et non le
           fond de panneau, parce que celui-ci est plus sombre que la page — il
           creusait là où il fallait lever. C'est la surface des modales et des
           menus : « ce qui est actif » garde une seule couleur. */
        return '<div class="xo-panel xo-panel--pad xo-panel--raise">'
            . '<label class="xo-prompt">'
            . '<span class="xo-prompt__sign">' . Icone::rendre('invite') . '</span>'
            . '<input type="text" id="chat-saisie" aria-label="Demande"'
            . ' data-depeche="' . ($ancre !== null ? (int) $ancre['id'] : '') . '"'
            . ' placeholder="' . ($ancre !== null ? 'Que demander sur cette dépêche ?' : 'Demandez, ou tapez /veille…') . '"'
            . ' autofocus>'
            . '</label>'
            . '<div class="xo-row" style="margin-top: 8px">'

            /* -- Les trois gestes qui parlent du fil, contre le champ --

               Ils étaient dispersés : « Fil neuf » et « Mémoire » en haut,
               dans la tuile Utilisateur ; « Outils » tout en bas de la colonne
               de veille, où il annonçait pourtant « aucun outil appelé **dans
               ce fil** » — l'état de la conversation, affiché dans la bande de
               la collecte. Trois gestes d'un même objet à trois endroits.

               Ici, ils tiennent avec ce qu'ils visent, exactement comme
               l'inspection tient contre le champ : on regarde le fil, on agit
               dessus, on écrit. Une rangée déjà là, aucune hauteur de plus —
               le champ ne recule pas d'un pixel.

               Le compteur d'outils garde son ton (accent tant qu'un appel
               tourne, danger si l'un a échoué) et son identifiant : c'est le
               seul signal qui dise s'il faut aller voir, et `majOutils()` le
               tient à jour sans savoir où il est posé. */
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="fil-neuf">'
            . Icone::rendre('fil-neuf') . ' Fil neuf</button>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="memoire">'
            . Icone::rendre('memoire') . ' Mémoire</button>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="outils">'
            . Icone::rendre('outils') . ' Outils '
            . '<span id="desk-outils-compte" class="xo-muted">' . $appels . '</span></button>'

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
            . '</div>'
            . '</div>';
    }
}
