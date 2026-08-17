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
       façons de chercher pour une même question, c'en serait deux de trop. */
    $html = $q !== ''
        ? Vue::lignesDepeches($base->chercherParMots($q, 12))
        : Vue::lignesEvenements($base->arbre([], 12));

    echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
