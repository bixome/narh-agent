<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

session_start();

/**
 * Le direct — l'antenne.
 *
 *   GET api/direct.php?action=ouvrir    — bascule en agent en direct
 *   GET api/direct.php?action=segment   — le segment suivant, déjà rendu
 *   GET api/direct.php?action=quart     — verse une note de quart, l'antenne continue
 *   GET api/direct.php?action=fermer    — repasse en conversation, rend la note de quart
 *
 * Un segment se compose en quelques millisecondes : c'est ce qui tient la
 * promesse des dix-sept secondes. Rien ici n'attend le modèle.
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
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'segment';

    if ($action === 'ouvrir') {
        Direct::demarrer();
        repondre(['ok' => true, 'antenne' => true, 'budget' => Direct::BUDGET]);
    }

    if ($action === 'fermer') {
        $bilan = Direct::arreter();

        /* La note de quart entre dans le fil comme un tour : elle se relit, se
           cite, et survit à la session. C'est la trace que la boucle doit
           laisser — sans elle, une heure d'antenne ne laisserait rien.

           Son bilan est **stocké**, pas recalculé : une tuile se refait parce
           qu'elle montre l'état présent, une note de quart fige ce qui a été
           dit à un moment précis. La recalculer demain donnerait la couverture
           de demain sous la date d'hier. */
        if ($bilan['segments'] > 0) {
            Agent::tourAjouter(
                'quart',
                'Note de quart — ' . $bilan['segments'] . ' segments à l\'antenne',
                [],
                $bilan,
            );
        }

        repondre([
            'ok'      => true,
            'antenne' => false,
            'note'    => Vue::noteDeQuart($bilan),
            'bilan'   => ['segments' => $bilan['segments'], 'sujets' => $bilan['sujets']],
        ]);
    }

    if (!Direct::enAntenne()) {
        repondre(['ok' => false, 'erreur' => "L'antenne est fermée."], 409);
    }

    /* Passer la main sans couper le direct.
       Fermer l'antenne produit déjà une note ; mais un quart se relaie plus
       souvent qu'il ne s'arrête, et rien ne justifiait qu'on doive éteindre
       pour transmettre. Même bilan, même tour dans le fil — seule l'extinction
       est retirée. */
    if ($action === 'quart') {
        $bilan = Direct::bilan();

        if ($bilan['segments'] === 0) {
            repondre(['ok' => false, 'erreur' => "Rien n'a encore été dit à l'antenne."], 409);
        }

        Agent::tourAjouter(
            'quart',
            'Note de quart — ' . $bilan['segments'] . ' segments à l\'antenne',
            [],
            $bilan,
        );

        Journal::noter('ok', 'direct', sprintf(
            'note de quart à la demande : %d segments, %d sujets',
            $bilan['segments'],
            $bilan['sujets'],
        ));

        repondre([
            'ok'      => true,
            'antenne' => true,
            'note'    => Vue::noteDeQuart($bilan),
            'bilan'   => ['segments' => $bilan['segments'], 'sujets' => $bilan['sujets']],
        ]);
    }

    /* La voix arrive **après** le segment, jamais avant : celui-ci est déjà à
       l'antenne quand on la demande. C'est ce qui permet de brancher un modèle
       lent sur un direct rapide sans jamais retarder le flux — au pire, il ne
       manque rien. */
    if ($action === 'voix') {
        $voix = Direct::voix();

        repondre($voix === null
            ? ['ok' => true, 'voix' => null]
            : ['ok' => true, 'voix' => $voix['texte'], 'rang' => $voix['rang'], 'jetons' => $voix['jetons']]);
    }

    $debut = microtime(true);
    $segment = Direct::prochain();
    $heure = date('H:i:s');

    repondre([
        'ok'     => true,
        'nature' => $segment['nature'],
        'heure'  => $heure,
        'html'   => Vue::segment($segment, $heure),
        // Mesuré et renvoyé : si la composition d'un segment approchait du
        // budget, c'est ici qu'on le verrait, pas dans une impression.
        'ms'     => (int) round((microtime(true) - $debut) * 1000),
    ]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
