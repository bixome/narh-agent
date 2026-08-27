<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/**
 * Un aperçu, sans rien inscrire dans le fil.
 *
 *   GET api/apercu.php?type=veille[&q=…]
 *
 * C'est la différence entre consulter et retenir : l'onglet Veille du Newsdesk
 * sert à jeter un œil, `/veille` pose une tuile qui reste comme trace datée.
 * Confondre les deux remplirait la conversation de tout ce qu'on a survolé.
 *
 * Le rendu vient de `Vue`, comme partout ailleurs (règle 2).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : 'veille';
    $base = new Base((string) narh_reglage('base_veille'));

    /* Le détail d'une dépêche, pour l'onglet Inspecté : le même rendu que
       partout, y compris son bouton « ouvrir l'article ». */
    if ($type === 'depeche') {
        $a = $base->article((int) ($_GET['id'] ?? 0));
        $fratrie = $a !== null && $a['groupe_id'] !== null
            ? $base->fratrie((int) $a['groupe_id'], (int) $a['id'])
            : [];

        echo json_encode(
            ['ok' => true, 'html' => Vue::inspecteur($a, $fratrie)],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        exit;
    }

    /* -- Un niveau de plongée --
       Le desk ne montre plus un détail *au-dessus* de sa liste mais *à la
       place* : chaque niveau remplace le précédent et rend la hauteur entière
       au contenu. Mesuré avant : quatre zones superposées ne laissaient que
       29 % de la colonne à la liste, et une seule carte y tenait.

       Les trois niveaux existaient déjà, chacun dans son coin — c'est la
       navigation qui manquait, pas la matière :

         1  l'événement   `Vue::inspecteur()` et sa fratrie « Ailleurs »
         2  la source     le texte, lu côté serveur, jamais par le navigateur
         3  la vérification  ce que des registres extérieurs en disent

       Rien n'est inscrit dans le fil : plonger est un geste de **consultation**.
       Ce qui mérite une trace se pose en tuile, et c'est un autre geste. */
    if ($type === 'niveau') {
        $n = (int) ($_GET['n'] ?? 1);
        $id = (int) ($_GET['id'] ?? 0);
        $a = $base->article($id);

        if ($a === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'erreur' => 'Dépêche introuvable.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        [$html, $titre] = match ($n) {
            2 => [
                Vue::lecture(Lecture::extraire(
                    Lecture::recuperer((string) $a['lien'])['html'] ?? '',
                    (string) $a['lien'],
                    (string) $a['titre'],
                )),
                (string) ($a['source_nom'] ?? 'la source'),
            ],
            3 => [
                Vue::verdicts(Osint::connus([(int) ($a['groupe_id'] ?? 0)])[(int) ($a['groupe_id'] ?? 0)] ?? [])
                    ?: '<p class="xo-muted">Aucun croisement pour ce sujet.</p>',
                'vérification',
            ],
            default => [
                Vue::inspecteur($a, $a['groupe_id'] !== null
                    ? $base->fratrie((int) $a['groupe_id'], (int) $a['id'])
                    : []),
                Util::tronquer((string) $a['titre'], 48),
            ],
        };

        echo json_encode(
            ['ok' => true, 'html' => $html, 'titre' => $titre],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        exit;
    }

    /* Le bandeau du mode dépêche, pour « Interroger l'agent dessus ».
       Rien n'est inscrit : l'ancre ne devient un fait qu'au premier message,
       ce qui est exactement la promesse de cette route. */
    if ($type === 'ancre') {
        $a = $base->article((int) ($_GET['id'] ?? 0));

        if ($a === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'erreur' => 'Dépêche introuvable.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(
            ['ok' => true, 'html' => Vue::ancre($a), 'titre' => (string) $a['titre']],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        exit;
    }

    /* Mot à mot dès qu'on cherche, comme l'outil et comme la tuile : trois
       façons de chercher pour une même question, c'en serait deux de trop.

       `Ecran::DESK_LIGNES` et non douze en dur : c'est la troisième route à
       bâtir la liste de l'onglet Veille, après `Ecran::contexte()` et
       `api/fils.php`. Trois nombres écrits séparément, et vider le champ de
       recherche ne rendait pas la liste qu'on avait avant de taper. */
    $html = $q !== ''
        ? Vue::lignesDepeches($base->chercherParMots($q, Ecran::DESK_LIGNES))
        : Vue::lignesEvenements(
            $base->arbre(['classement' => 'consistance', 'description' => true, 'traitement' => true], Ecran::DESK_LIGNES),
        );

    echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
