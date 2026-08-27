<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/**
 * La passe de croisement : ce que des sources extérieures disent de ces sujets.
 *
 *   POST api/osint.php   {"groupes": [12, 34, …]}
 *
 * **Après l'affichage, jamais pendant** — la règle de la lecture hors réponse,
 * et ici elle n'est pas un confort. Le direct tient sur une contrainte
 * fondatrice : jamais plus de dix-sept secondes de blanc, et un segment est
 * composé en 30 à 45 ms précisément parce qu'il n'attend rien. Un appel réseau
 * qui met deux secondes, ou qui échoue, n'entre pas dans ce budget. Le segment
 * part donc non vérifié et le verdict le rejoint — la chaîne se lit dans le
 * journal, où le segment et son verdict se suivent à une seconde d'intervalle.
 *
 * Le modèle de cette route est `api/liens.php`, qui décrivait déjà ce motif
 * pour la vérification des liens. Elle n'a jamais eu d'appelant ; celle-ci en a
 * un, et c'est ce qui fait la différence entre un motif et une intention.
 *
 * Le verdict est mis en cache (`osint_vu`) : sans lui, chaque sondage du
 * navigateur relancerait les mêmes requêtes sortantes pour redécouvrir ce qu'on
 * savait déjà — et les services gratuits qu'on interroge ne le méritent pas.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Au-delà, ce n'est plus une passe mais un balayage. */
const GROUPES_MAX = 12;

function repondre(array $charge, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        repondre(['ok' => false, 'erreur' => 'POST attendu.'], 405);
    }

    $brut = json_decode((string) file_get_contents('php://input'), true);
    $ids = is_array($brut['groupes'] ?? null) ? $brut['groupes'] : [];
    $ids = array_values(array_unique(array_filter(
        array_map(static fn (mixed $i): int => (int) $i, $ids),
        static fn (int $i): bool => $i > 0,
    )));

    if ($ids === []) {
        repondre(['ok' => true, 'verdicts' => new stdClass(), 'croises' => 0]);
    }

    $ids = array_slice($ids, 0, GROUPES_MAX);
    $base = new Base((string) narh_reglage('base_veille'));

    /* Le budget borne la passe entière, pas chaque appel : un service lent ne
       doit pas pouvoir faire attendre le navigateur au-delà de ce qu'on a
       décidé. Ce qui n'a pas été croisé cette fois le sera au sondage suivant —
       rien ne dépend de la fraîcheur d'un verdict. */
    $budget = (int) narh_reglage('osint_budget');
    $debut = microtime(true);
    $croises = 0;

    foreach ($ids as $id) {
        if (microtime(true) - $debut > $budget) {
            break;
        }

        $g = $base->groupe($id);
        if ($g === null) {
            continue;
        }
        if (Osint::croiser($g) !== null) {
            $croises++;
        }
    }

    /* On rend **tous** les verdicts connus des sujets demandés, pas seulement
       ceux de cette passe : le navigateur repose les lignes telles qu'elles
       sont, et un verdict rendu il y a dix minutes doit réapparaître au
       rechargement sans être recalculé. */
    $verdicts = [];
    foreach (Osint::connus($ids) as $id => $liste) {
        $verdicts[(string) $id] = Vue::verdicts($liste);
    }

    repondre([
        'ok'       => true,
        'verdicts' => $verdicts === [] ? new stdClass() : $verdicts,
        'croises'  => $croises,
    ]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
