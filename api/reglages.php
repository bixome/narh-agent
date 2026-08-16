<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/**
 * Les réglages de l'agent.
 *
 * Seulement ceux qui changent son comportement : modèle, température, outils,
 * et le nom affiché. Les chemins de base et les cadences restent dans
 * `config/reglages.php` — ils doivent se lire sans PHP applicatif ni base, et
 * se tromper dessus depuis un écran couperait l'écran qui sert à les corriger.
 *
 *   POST api/reglages.php  (modele, temperature, outils, utilisateur)
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        repondre(['ok' => false, 'erreur' => 'POST attendu.'], 405);
    }

    $reglages = Agent::reglages();

    $modele = trim((string) ($_POST['modele'] ?? ''));
    if ($modele !== '') {
        $reglages['modele'] = mb_substr($modele, 0, 80);
    }

    // Bornée : une température hors [0,1] ne rend pas le modèle plus créatif,
    // elle le rend incohérent — autant refuser la valeur que déboguer la sortie.
    if (isset($_POST['temperature'])) {
        $reglages['temperature'] = max(0.0, min(1.0, (float) str_replace(',', '.', (string) $_POST['temperature'])));
    }

    $reglages['outils_auto'] = ($_POST['outils'] ?? '') === '1';

    Agent::reglagesSauver($reglages);

    /* Le nom vit dans `reglages.local.php`, jamais versionné : c'est un réglage
       de poste, au même titre qu'un chemin de base. On réécrit le fichier
       entier plutôt que de le rapiécer — il n'a qu'une clé, et une écriture
       partielle laisserait un fichier PHP à moitié valide. */
    $nom = trim((string) ($_POST['utilisateur'] ?? ''));
    if ($nom !== '') {
        $local = NARH_RACINE . '/config/reglages.local.php';
        $garde = is_file($local) ? require $local : [];
        $garde = is_array($garde) ? $garde : [];
        $garde['utilisateur'] = mb_substr($nom, 0, 40);

        file_put_contents(
            $local,
            "<?php\ndeclare(strict_types=1);\n\n"
            . "/* Réglages de poste — jamais versionné. Écrit depuis l'écran. */\n\n"
            . 'return ' . var_export($garde, true) . ";\n",
        );
    }

    Journal::noter('ok', 'écran', 'réglages enregistrés : ' . $reglages['modele']);

    repondre(['ok' => true]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
