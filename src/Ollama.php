<?php
declare(strict_types=1);

/**
 * Client minimal pour l'API Ollama locale. Aucune dépendance : cURL seul.
 *
 * Porté depuis otow-agent — le protocole n'a rien de spécifique au projet
 * d'origine, seule la conversation qui l'entoure change (CLAUDE.md, règle 4 :
 * ce client ne décide de rien qui touche la veille, il ne fait que parler au
 * moteur local).
 */
final class Ollama
{
    public function __construct(
        private readonly string $url = 'http://127.0.0.1:11434',
    ) {
    }

    public function disponible(): bool
    {
        $ch = curl_init($this->url . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $ok = curl_exec($ch) !== false;
        curl_close($ch);

        return $ok;
    }

    /**
     * L'état d'exécution : quels modèles sont réellement chargés en mémoire, et
     * quand Ollama les déchargera. La seule vue « en direct » du moteur —
     * `/api/tags` ne dit que ce qui est installé sur disque.
     *
     * @return array{version: string, charges: list<array<string, mixed>>}
     */
    public function etat(): array
    {
        $ps = $this->lire('/api/ps', 3);
        $version = $this->lire('/api/version', 2);

        $charges = [];
        foreach ($ps['models'] ?? [] as $m) {
            $charges[] = [
                'nom'            => (string) $m['name'],
                'vram'           => (int) ($m['size_vram'] ?? 0),
                // La fenêtre réelle du modèle chargé : c'est le dénominateur de
                // la jauge de contexte, et il ne se devine pas — Ollama peut la
                // fixer autrement que la valeur par défaut du modèle.
                'contexte'       => (int) ($m['context_length'] ?? 0),
                'parametres'     => (string) ($m['details']['parameter_size'] ?? '?'),
                'quantification' => (string) ($m['details']['quantization_level'] ?? '?'),
                'expire_dans'    => isset($m['expires_at'])
                    ? max(0, strtotime((string) $m['expires_at']) - time())
                    : null,
            ];
        }

        return ['version' => (string) ($version['version'] ?? '?'), 'charges' => $charges];
    }

    /** @return array<string, mixed> */
    private function lire(string $chemin, int $timeout): array
    {
        $ch = curl_init($this->url . $chemin);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout]);
        $body = curl_exec($ch);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Une phrase, tout de suite ou pas du tout.
     *
     * Rien à voir avec `repondre()` : pas de flux, pas d'outils, pas de boucle,
     * et surtout un **délai plafonné**. C'est ce qu'il faut au direct, où une
     * réponse qui arrive en retard ne vaut plus rien — le segment est déjà à
     * l'antenne.
     *
     * Ne lève jamais : un moteur injoignable, lent ou déchargé rend `null`, et
     * l'appelant continue sans. Une voix est un enrichissement ; la faire
     * échouer bruyamment casserait ce qu'elle devait embellir.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return array{texte: string, jetons: int}|null
     */
    public function phrase(
        string $modele,
        array $messages,
        float $temperature = 0.7,
        int $timeout = 8,
        int $maxJetons = 60,
        bool $json = false,
    ): ?array {
        $charge = [
            'model'    => $modele,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature' => $temperature,
                // Borner la génération autant que le délai : sans ça, le modèle
                // part sur un paragraphe et le timeout coupe au milieu d'un mot.
                'num_predict' => $maxJetons,
            ],
        ];

        /* Contraindre la sortie à du JSON valide. Demandé par `Ia`, qui attend
           un objet et non une phrase : sans cette contrainte, un modèle de 3 B
           préfixe volontiers son objet d'un « Voici le résultat : » qui fait
           échouer le décodage une fois sur trois. */
        if ($json) {
            $charge['format'] = 'json';
        }

        $ch = curl_init($this->url . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($charge, JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $corps = curl_exec($ch);
        curl_close($ch);

        if (!is_string($corps) || $corps === '') {
            return null;
        }

        $data = json_decode($corps, true);
        $texte = trim((string) ($data['message']['content'] ?? ''));

        return $texte === '' ? null : ['texte' => $texte, 'jetons' => (int) ($data['eval_count'] ?? 0)];
    }

    /**
     * Envoie une conversation à /api/chat et streame chaque jeton via $surJeton.
     * Quand le modèle choisit d'appeler un outil plutôt que de répondre, aucun
     * jeton de contenu n'arrive : le flux se résume à un bloc `tool_calls`,
     * capturé ici et renvoyé tel quel — à l'appelant de l'exécuter et de
     * relancer la conversation.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param list<array<string, mixed>>                 $outils
     * @param callable(string $jeton): void               $surJeton
     * @return array{content: string, tool_calls: array, prompt_eval_count: int, eval_count: int, eval_duration: int}
     */
    public function repondre(
        string $modele,
        array $messages,
        callable $surJeton,
        float $temperature = 0.7,
        array $outils = [],
    ): array {
        $contenu = '';
        $appelsOutils = [];
        $stats = ['prompt_eval_count' => 0, 'eval_count' => 0, 'eval_duration' => 0];

        $charge = [
            'model'    => $modele,
            'messages' => $messages,
            'stream'   => true,
            'options'  => ['temperature' => $temperature],
        ];
        if ($outils !== []) {
            $charge['tools'] = $outils;
        }

        /* Un seul octet non-UTF8 — venu d'un fichier lu par un outil, ou d'un
           client qui poste en Latin-1 — fait échouer json_encode() et perd la
           requête entière : mesuré, la conversation s'arrêtait sur « Malformed
           UTF-8 characters » sans qu'un jeton soit sorti. On substitue plutôt
           que d'échouer, comme sur les trames SSE. */
        $ch = curl_init($this->url . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($charge, JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_WRITEFUNCTION  => function ($curl, string $bloc) use (&$contenu, &$appelsOutils, &$stats, $surJeton): int {
                foreach (explode("\n", $bloc) as $ligne) {
                    $ligne = trim($ligne);
                    if ($ligne === '') {
                        continue;
                    }
                    $obj = json_decode($ligne, true);
                    if (!is_array($obj)) {
                        continue;
                    }
                    $jeton = (string) ($obj['message']['content'] ?? '');
                    if ($jeton !== '') {
                        $contenu .= $jeton;
                        $surJeton($jeton);
                    }
                    if (!empty($obj['message']['tool_calls'])) {
                        $appelsOutils = $obj['message']['tool_calls'];
                    }
                    if (!empty($obj['done'])) {
                        $stats['prompt_eval_count'] = (int) ($obj['prompt_eval_count'] ?? 0);
                        $stats['eval_count'] = (int) ($obj['eval_count'] ?? 0);
                        $stats['eval_duration'] = (int) ($obj['eval_duration'] ?? 0);
                    }
                }

                return strlen($bloc);
            },
        ]);

        $ok = curl_exec($ch);
        $erreur = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new RuntimeException("Flux Ollama interrompu : $erreur");
        }

        return array_merge(['content' => $contenu, 'tool_calls' => $appelsOutils], $stats);
    }
}
