<?php
declare(strict_types=1);

/**
 * Le point d'entrée du rafraîchissement.
 *
 * L'écran ne recharge jamais : il redemande ici ce qui est arrivé depuis le
 * dernier identifiant qu'il connaît. La réponse contient du HTML **déjà rendu**
 * par Vue — le navigateur n'a qu'à le poser. Un seul gabarit sert donc au
 * premier écran et à tout ce qui suit (CLAUDE.md, règle 2).
 *
 *   GET  api.php?action=flux&depuis=1240&rubrique=une&niveau=2&vue=arbre
 *   GET  api.php?action=article&id=1240
 *   GET  api.php?action=statut&id=1240&valeur=suivi&vue=arbre
 *   GET  api.php?action=cycle          — force un relevé
 *
 * Toutes les réponses sont du JSON, sans cache.
 */

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $charge */
function repondre(array $charge, int $code = 200): never
{
    http_response_code($code);
    echo json_encode(
        $charge,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

function param(string $nom, string $defaut = ''): string
{
    $v = $_GET[$nom] ?? $defaut;

    return is_string($v) ? trim($v) : $defaut;
}

try {
    $maintenant = time();
    $base = new Base((string) narh_reglage('base_veille'));
    $action = param('action', 'flux');

    /* ---- Détail d'une dépêche ------------------------------------------- */

    if ($action === 'article') {
        $id = (int) param('id', '0');
        $article = $base->article($id);
        if ($article === null) {
            repondre(['ok' => false, 'erreur' => 'Dépêche introuvable.'], 404);
        }

        $fratrie = $article['groupe_id'] !== null
            ? $base->fratrie((int) $article['groupe_id'], $id)
            : [];

        repondre([
            'ok'    => true,
            'id'    => $id,
            'lien'  => (string) $article['lien'],
            'titre' => (string) $article['titre'],
            'html'  => Vue::inspecteur($article, $fratrie),
        ]);
    }

    /* ---- Marquage de desk ------------------------------------------------
       Avant la collecte, et c'est délibéré : un cycle opportuniste peut durer
       `cycle_max` secondes, et un geste doit répondre tout de suite. La valeur
       passe par Base::marquer(), qui n'accepte que les quatre statuts connus. */

    if ($action === 'statut') {
        $id = (int) param('id', '0');
        $article = $base->article($id);
        if ($article === null || $article['groupe_id'] === null) {
            repondre(['ok' => false, 'erreur' => 'Dépêche introuvable ou sans événement.'], 404);
        }

        $groupeId = (int) $article['groupe_id'];
        $valeur = param('valeur');
        if (!$base->marquer($groupeId, $valeur, $maintenant)) {
            repondre(['ok' => false, 'erreur' => 'Statut inconnu.'], 400);
        }

        /* Un geste de desk entre dans la chronologie commune au même titre
           qu'un cycle ou qu'un tour de modèle : c'est ce qui permettra de lire
           « écarté à 04:31, l'agent l'avait signalé à 04:29 » (règle 7). */
        Journal::noter('ok', 'écran', sprintf(
            '%s : %s',
            $valeur === '' ? 'démarquage' : $valeur,
            Util::tronquer((string) $article['titre'], 70),
        ));

        /* Le marquage porte sur l'événement : toutes ses dépêches présentes à
           l'écran changent d'état, pas seulement celle qui était sélectionnée.
           On renvoie leur balisage refait — le rendu reste en PHP. En arbre,
           c'est le nœud entier qu'on repose. */
        $lignes = [];
        $noeud = null;

        if (param('vue') === 'arbre') {
            $groupes = $base->arbre(['groupe' => $groupeId, 'statut' => $valeur], 1);
            $noeud = $groupes !== [] ? Vue::noeud($groupes[0]) : null;
        } else {
            foreach ($base->flux(['groupe' => $groupeId, 'statut' => $valeur, 'limite' => 60]) as $a) {
                $lignes[] = ['id' => (int) $a['id'], 'html' => Vue::ligne(Piece::depeche($a))];
            }
        }

        repondre([
            'ok'     => true,
            'id'     => $id,
            'groupe' => $groupeId,
            'statut' => $valeur,
            'lignes' => $lignes,
            'noeud'  => $noeud,
        ]);
    }

    /* ---- L'état seul -----------------------------------------------------
       La barre d'état se rafraîchit sans avoir besoin du fil : depuis que la
       veille est une tuile et non un écran, redemander trois cents lignes pour
       mettre à jour quatre compteurs serait payer cher un chiffre. Le cycle
       opportuniste, lui, reste — c'est ce qui fait tourner la collecte quand
       aucun démon ne le fait.

       **Cette route a donc deux temps de réponse, et c'est voulu.** Mesuré sur
       trente sondages sans démon : vingt-huit sans cycle tiennent en 53 ms de
       médiane et 79 ms au pire, les deux qui ont relevé ont pris 1 354 et
       1 878 ms. Les pics sont les cycles, un pour un — ils vont chercher des
       flux sur le réseau, et `cycle_max` les borne à quinze secondes.

       Le piège est dans la lecture : une médiane saine avec de rares pointes
       à deux secondes ressemble à s'y méprendre à une contention, et l'on part
       chercher un verrou qui n'existe pas. Pour que l'écran ne paie jamais la
       collecte, c'est `php cli.php --veille` en fond et `collecte_web` à false
       dans `config/reglages.local.php` — la route retombe alors à ses 53 ms. */

    if ($action === 'etat') {
        $collecteur = new Collecteur($base);
        $releve = null;

        if (narh_reglage('collecte_web', true) && $collecteur->perime($maintenant) && !Collecteur::occupe()) {
            $releve = $collecteur->cycle(false, (int) narh_reglage('cycle_max', 15));
            Ecran::journaliserCycle($base, $releve, 'sondage');
            $maintenant = time();
        }

        repondre([
            'ok'         => true,
            'heure'      => date('H:i:s', $maintenant),
            'stats'      => $base->stats($maintenant),
            'cycle'      => $releve ?? $base->cycle(),
            'releve'     => $releve !== null,
        ]);
    }

    /* ---- Collecte -------------------------------------------------------- */

    $collecteur = new Collecteur($base);
    $releve = null;

    // Le sondage relance lui-même un cycle quand aucun démon ne tourne. Le
    // budget borne la durée : mieux vaut un cycle partiel qu'un écran figé.
    $force = $action === 'cycle';
    if ($force || (narh_reglage('collecte_web', true) && $collecteur->perime($maintenant) && !Collecteur::occupe())) {
        $releve = $collecteur->cycle($force, (int) narh_reglage('cycle_max', 15));
        Ecran::journaliserCycle($base, $releve, $force ? 'à la demande' : 'sondage');
        $maintenant = time();
    }

    /* ---- Le fil ---------------------------------------------------------- */

    $depuis = (int) param('depuis', '0');
    $filtres = [
        'rubrique' => param('rubrique', 'tout'),
        'niveau'   => (int) param('niveau', '0'),
        'q'        => param('q'),
        'tri'      => param('tri', 'publication') === 'arrivee' ? 'arrivee' : 'publication',
        'source'   => param('source'),
        'statut'   => param('statut'),
    ];

    $premier = $depuis === 0;
    $arbre = param('vue') === 'arbre';

    /* En arbre, une dépêche neuve ne s'ajoute pas au bout du fil : elle rejoint
       son événement, dont le compteur et le niveau changent. On renvoie donc des
       nœuds entiers — le navigateur repose le bloc, il n'assemble rien.
       `vu_dernier` dit exactement lesquels ont bougé. */
    $noeuds = [];
    if ($arbre) {
        $groupes = $premier
            ? $base->arbre($filtres, (int) narh_reglage('arbre_max', 120))
            : $base->arbreDepuis($filtres, (int) param('promus_depuis', '0'));

        foreach ($groupes as $g) {
            $noeuds[] = [
                'groupe' => (int) $g['id'],
                'niveau' => (int) $g['niveau'],
                'tri'    => (int) $g['tri'],
                'titre'  => (string) $g['titre'],
                'html'   => Vue::noeud($g),
            ];
        }
    }

    $articles = $arbre ? [] : $base->flux($filtres + [
        'depuis' => $depuis,
        'limite' => $premier ? (int) narh_reglage('flux_max', 300) : 120,
    ]);

    // Un lot incrémental se lit de bas en haut : la plus ancienne des nouvelles
    // dépêches s'insère en premier, la plus récente finit en tête.
    if (!$premier) {
        $articles = array_reverse($articles);
    }

    $stats = $base->stats($maintenant);
    $cycle = $base->cycle();

    $lignes = [];
    $rang = 0;
    foreach ($articles as $a) {
        $lignes[] = [
            'id'     => (int) $a['id'],
            'niveau' => (int) $a['niveau'],
            'titre'  => (string) $a['titre'],
            'source' => (string) $a['source_nom'],
            'html'   => Vue::ligne(Piece::depeche($a), $rang++),
        ];
    }

    /* ---- Les panneaux latéraux ------------------------------------------
       Ils changent moins vite que le fil. Plutôt que de les renvoyer à chaque
       sondage, on en calcule une empreinte : si elle n'a pas bougé depuis le
       sondage précédent, la réponse ne les transporte pas. */

    $relance = array_map('intval', array_column($base->aRelancer(
        $maintenant,
        (int) narh_reglage('relance_minutes', 45),
        (int) narh_reglage('relance_niveau', Alerte::ALERTE)
    ), 'id'));

    $alertes = Vue::alertes(
        $base->alertes($maintenant - 21600, Alerte::ALERTE, (int) narh_reglage('alertes_max', 12)),
        $relance
    );

    $journal = Vue::activite(Journal::lire((int) narh_reglage('journal_max', 60)));

    $empreinte = md5($alertes . $journal);
    $panneaux = $empreinte === param('sig') ? null : [
        'alertes' => $alertes,
        'journal' => $journal,
    ];

    repondre([
        'ok'         => true,
        'maintenant' => $maintenant,
        'heure'      => date('H:i:s', $maintenant),
        'dernier_id' => $stats['dernier_id'],
        'premier'    => $premier,
        'lignes'     => $lignes,
        'noeuds'     => $noeuds,
        // En arbre, le nœud refait porte déjà le niveau : pas de promotion à
        // rattraper ligne par ligne.
        'promus'     => !$arbre && $depuis > 0 ? $base->promus((int) param('promus_depuis', '0'), $depuis) : [],
        'sig'        => $empreinte,
        'panneaux'   => $panneaux,
        'stats'      => $stats,
        'cycle'      => $releve ?? $cycle,
        'releve'     => $releve !== null,
    ]);
} catch (Throwable $e) {
    repondre([
        'ok'     => false,
        'erreur' => $e->getMessage(),
        'ou'     => basename($e->getFile()) . ':' . $e->getLine(),
    ], 500);
}
