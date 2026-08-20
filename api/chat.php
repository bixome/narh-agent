<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

session_start();

/* Le corps de cette réponse est un flux d'événements, pas une page : un warning
   PHP imprimé au milieu casse la trame en cours et le client cesse de lire. On
   les consigne au lieu de les afficher (CLAUDE.md, pièges déjà rencontrés). */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

function sse(string $type, array $donnees = []): void
{
    // Un octet non-UTF8 venu d'un fichier lu par un outil ferait échouer
    // json_encode(), et perdrait la trame entière.
    echo 'data: ' . json_encode(
        array_merge(['type' => $type], $donnees),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    ) . "\n\n";
    flush();
}

/* La frontière du système : ce qui entre est réparé une fois, ici, plutôt que
   partout en aval. Un client qui poste en Latin-1 ne doit pas faire échouer la
   sérialisation du contexte trois couches plus loin. */
$message = trim((string) ($_POST['message'] ?? ''));
if (!mb_check_encoding($message, 'UTF-8')) {
    $message = mb_convert_encoding($message, 'UTF-8', 'Windows-1252');
}
if ($message === '') {
    sse('error', ['message' => 'Message vide.']);
    exit;
}

$reglages = Agent::reglages();
$ollama = new Ollama((string) narh_reglage('ollama')['url']);

if (!$ollama->disponible()) {
    sse('error', ['message' => 'Ollama injoignable sur ' . narh_reglage('ollama')['url'] . '.']);
    Journal::noter('error', 'agent', 'Ollama injoignable');
    exit;
}

/* Le pont, sens veille → agent : la dépêche entre dans le fil avant la
   question, pour que le modèle l'ait sous les yeux en y répondant. Le journal
   le dit, parce que c'est exactement le genre d'enchaînement que la règle 7
   existe pour rendre lisible. */
$depeche = (int) ($_POST['depeche'] ?? 0);
if ($depeche > 0 && Agent::ancrer($depeche)) {
    Journal::noter('info', 'agent', "interrogé sur la dépêche #$depeche");
}

Agent::tourAjouter('user', $message);
Journal::noter('info', 'agent', 'requête : ' . mb_strimwidth($message, 0, 80, '…'));

/* Le fil est créé, la session n'a plus rien à écrire : on rend le verrou
   **avant** de générer. C'était le pire blocage de l'écran — PHP tient le
   fichier de session jusqu'à la fin du script, et ce script-ci dure le temps
   d'une réponse entière. Mesuré pendant qu'il streamait : `api.php?action=etat`
   passait de 57 ms à 3,4 s, `api/fils.php` de 130 ms à 18,5 s, et la saisie
   accusait le même retard — on tapait derrière la file.

   Ce qui suit ne fait plus que lire le fil, et `filId()` s'en souvient. */
Agent::filRendreLaMain();

try {
    $debut = microtime(true);
    $historique = array_slice(Agent::tours(), -20);
    $resultat = Agent::repondre($ollama, $reglages, $historique, function (string $type, array $donnees): void {
        sse($type, $donnees);
    });

    /* Les tuiles que l'agent a décidé de poser voyagent avec sa réponse : elles
       ne sont pas une décoration ajoutée après coup, mais la trace de ce qu'il
       a consulté pour répondre. */
    Agent::tourAjouter(
        'assistant',
        $resultat['content'],
        $resultat['etapes'],
        null,
        $resultat['eval_count'],
        $resultat['tuiles'] ?? [],
        $resultat['contexte'] ?? 0,
    );
    Memoire::fermerOutils(Agent::filId());

    Journal::noter(
        'ok',
        'agent',
        sprintf('réponse : %d jetons', $resultat['eval_count']),
        (int) round((microtime(true) - $debut) * 1000),
    );

    sse('fin', ['fil_id' => Agent::filId(), 'jetons' => $resultat['eval_count']]);
} catch (Throwable $e) {
    sse('error', ['message' => $e->getMessage()]);
    Journal::noter('error', 'agent', $e->getMessage());
}
