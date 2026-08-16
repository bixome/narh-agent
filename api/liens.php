<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/**
 * La passe de vérification : lesquelles de ces sources répondent vraiment ?
 *
 * Une source affichée qui mène à une 404 est pire qu'une source absente — elle
 * donne l'apparence de la vérifiabilité sans la fournir. Les flux RSS gardent
 * des adresses qui expirent, changent, ou passent derrière un mur.
 *
 * Séparée du rendu à dessein (CLAUDE.md, règles du projet) : vérifier pendant
 * la réponse ajouterait une demi-seconde par source au moment le plus sensible,
 * celui où l'on attend. L'écran affiche donc d'abord, puis estompe ce qui ne
 * répond pas.
 *
 *   POST api/liens.php   {"liens": ["https://…", …]}
 *
 * Le verdict est mis en cache (`lien_vu`) : sans lui, rouvrir un fil de trente
 * tours relancerait trente requêtes sortantes pour redécouvrir ce qu'on savait
 * déjà. Chez otow-agent ce cache était un `data/liens.json` à côté de la base —
 * il est ici une table, comme le reste.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Au-delà, ce n'est plus une passe de vérification mais un balayage. */
const LIENS_MAX = 24;

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
    $liens = is_array($brut['liens'] ?? null) ? $brut['liens'] : [];
    $liens = array_values(array_unique(array_filter(
        array_map(static fn (mixed $l): string => is_string($l) ? trim($l) : '', $liens),
        static fn (string $l): bool => $l !== '',
    )));

    if ($liens === []) {
        repondre(['ok' => true, 'verdicts' => new stdClass(), 'verifies' => 0]);
    }

    $verdicts = [];
    $verifies = 0;

    foreach (array_slice($liens, 0, LIENS_MAX) as $lien) {
        $connu = Corpus::lienConnu($lien);

        if ($connu !== null) {
            $verdicts[$lien] = $connu;
            continue;
        }

        /* Le seul point sortant du projet, ici comme partout : `Lecture` porte
           les gardes contre les adresses privées, et cette route n'a pas à les
           redire. */
        $ok = Lecture::repond($lien);
        Corpus::noterLien($lien, $ok);
        $verdicts[$lien] = $ok;
        $verifies++;
    }

    $morts = count(array_filter($verdicts, static fn (bool $v): bool => !$v));
    if ($verifies > 0) {
        Journal::noter(
            $morts > 0 ? 'warn' : 'ok',
            'liens',
            sprintf('%d liens vérifiés, %d sans réponse', $verifies, $morts)
        );
    }

    repondre([
        'ok'       => true,
        'verdicts' => $verdicts,
        'verifies' => $verifies,
        'morts'    => $morts,
    ]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
