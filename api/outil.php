<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/* Pas de session avant d'en avoir besoin : `Outils::executer()` tourne juste
   au-dessus du tour qu'on verse, et un outil peut lire un article ou chercher
   sur le web. L'ouvrir ici aurait tenu l'écran verrouillé pendant ce
   temps-là ; `Agent::filId()` l'ouvre au bon moment, une fois l'outil rendu. */

/**
 * Lancer un outil à la main, depuis le Newsdesk.
 *
 *   GET api/outil.php?nom=rechercher_actualites&valeur=incendies
 *
 * Le résultat **entre dans le fil** comme un tour, avec sa tuile s'il en a
 * une : un geste nommé laisse une trace (règle 6). C'est aussi ce qui le rend
 * utile au tour suivant — le modèle verra ce qu'on vient de chercher, comme si
 * lui-même l'avait cherché, jusqu'à ce que la trace soit consommée.
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
    $nom = is_string($_GET['nom'] ?? null) ? $_GET['nom'] : '';
    $meta = Outils::metadonnees($nom);
    if ($meta === null) {
        repondre(['ok' => false, 'erreur' => "Outil inconnu : $nom"], 400);
    }

    /* Le formulaire n'a qu'un champ, et son nom vient du schéma de l'outil :
       le navigateur envoie une valeur, le serveur sait à quel paramètre elle
       correspond. Un outil sans paramètre ne reçoit rien. */
    $arguments = [];
    $valeur = trim((string) ($_GET['valeur'] ?? ''));
    if ($meta['champ'] !== null && $valeur !== '') {
        $arguments[$meta['champ']] = $valeur;
    }

    if ($meta['champ'] !== null && $meta['requis'] && $valeur === '') {
        repondre(['ok' => false, 'erreur' => 'Cet outil demande ' . $meta['champ'] . '.'], 400);
    }

    $debut = microtime(true);
    $sortie = Outils::executer($nom, $arguments);
    $ms = (int) round((microtime(true) - $debut) * 1000);

    $etape = ['outil' => $nom, 'arguments' => $arguments, 'ok' => $sortie['ok'], 'resultat' => $sortie['resultat']];
    $tuile = $sortie['ok'] ? Outils::tuilePour($nom, $arguments) : null;

    /* Le tour porte le résumé, pas le JSON : c'est ce que lira le modèle si la
       conversation continue, et ce que lit l'utilisateur en attendant. Le JSON
       complet reste dans l'étape, visible à l'inspection. */
    Agent::tourAjouter(
        'outil',
        Outils::resumer($nom, $sortie['resultat']),
        [$etape],
        null,
        0,
        $tuile !== null ? [$tuile] : [],
    );

    Journal::noter(
        $sortie['ok'] ? 'ok' : 'warn',
        'écran',
        'outil ' . $nom . ($valeur !== '' ? " « $valeur »" : '') . ($sortie['ok'] ? '' : ' — échec'),
        $ms,
    );

    // Le tour est versé : le rendu qui suit n'a plus qu'à lire.
    Agent::filRendreLaMain();

    $fil = Agent::filId();

    /* `ok: true` dit que la **route** a répondu, pas que l'outil a réussi :
       `Outils::executer()` rend un échec plutôt que de lever, et cet échec est
       une trace légitime à conserver. Le compteur, lui, doit distinguer les
       deux — il annonçait « 2 » d'un ton neutre après un outil raté. */
    $etat = Memoire::etatOutils($fil);

    repondre([
        'ok'     => true,
        'tours'  => Vue::tours(Memoire::messages($fil)),
        'outils' => Vue::outils(Memoire::outils($fil, 20)),
        'compte' => $etat['compte'],
        'echecs' => $etat['echecs'],
    ]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
