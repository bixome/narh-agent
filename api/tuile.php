<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

session_start();

/**
 * Poser une tuile dans la conversation.
 *
 * Une commande qui montre quelque chose n'ouvre pas un écran : elle verse un
 * tour dans le fil, portant le descripteur de ce qu'il faut afficher. La tuile
 * entre donc dans la chronologie au même titre qu'une question ou qu'une
 * réponse — c'est ce qui permet de relire un fil et de voir *ce qu'on
 * regardait* au moment où on l'a demandé.
 *
 *   GET api/tuile.php?type=veille[&q=…][&id=…]
 *
 * La réponse contient la conversation entière, déjà rendue (règle 2).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function repondre(array $charge, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
    if (!isset(Tuile::TITRES[$type])) {
        repondre(['ok' => false, 'erreur' => "Tuile inconnue : $type"], 400);
    }

    $params = [];
    if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
        $params['q'] = mb_substr($q, 0, 80);
    }
    if (($id = (int) ($_GET['id'] ?? 0)) > 0) {
        $params['id'] = $id;
    }

    $tuile = new Tuile($type, $params);

    /* Le libellé du tour dit ce qui a été demandé, pas ce qui est montré : la
       tuile se refait à chaque lecture, son contenu d'aujourd'hui ne décrirait
       pas le geste d'hier. */
    $dit = $params === []
        ? 'Ouvrir : ' . mb_strtolower($tuile->titre())
        : 'Ouvrir : ' . mb_strtolower($tuile->titre()) . ' — ' . implode(', ', array_map(
            static fn (string $c, mixed $v): string => "$c $v",
            array_keys($params),
            $params,
        ));

    Agent::tourAjouter('tuile', $dit, [], null, 0, [$tuile]);
    Journal::noter('info', 'écran', 'tuile ' . $type . ($params !== [] ? ' (' . json_encode($params, JSON_UNESCAPED_UNICODE) . ')' : ''));

    $fil = Agent::filId();

    repondre([
        'ok'    => true,
        'fil'   => $fil,
        'tours' => Vue::tours($fil > 0 ? Memoire::messages($fil) : []),
    ]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
