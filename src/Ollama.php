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
    /* La fenêtre par défaut, en jetons.
       Elle vit ici et pas seulement dans les réglages parce que **les trois
       routes doivent demander la même** : Ollama tient une instance chargée
       par fenêtre, et deux valeurs différentes font recharger le modèle d'un
       appel à l'autre — seize secondes, à chaque bascule entre la
       conversation et le direct. `config/reglages.php` reste ce qui décide ;
       cette constante n'est que le repli commun. */
    public const CONTEXTE = 8192;

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
     * Charger le modèle en mémoire, ou l'en sortir — sans rien générer.
     *
     * Le direct plafonne sa voix à quelques secondes (`Direct::VOIX_DELAI`),
     * or **charger** un modèle de 8 milliards de paramètres en demande seize
     * (mesuré ici, RTX 3060 Ti). Le premier segment après un déchargement
     * était donc muet à coup sûr : le délai partait entièrement dans la montée
     * en VRAM, sans qu'un jeton soit produit. On paie ce prix **hors
     * chronomètre**, à l'ouverture de l'antenne.
     *
     * `$residence` est le temps pendant lequel Ollama garde le modèle chargé
     * après une requête. À 0, il le décharge immédiatement : c'est ce qu'on
     * veut en fermant l'antenne, sous peine de retenir près de 6 Gio de VRAM
     * sur une machine qui sert aussi à autre chose.
     *
     * Ne lève jamais, et n'attend pas la fin : un moteur absent n'est pas une
     * raison de retarder l'ouverture d'un direct qui, lui, sait se passer de
     * voix.
     */
    public function residence(string $modele, int $secondes, int $contexte = self::CONTEXTE): void
    {
        $ch = curl_init($this->url . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => $modele,
                'messages'   => [],
                'stream'     => false,
                'keep_alive' => $secondes,
                // La même fenêtre que partout ailleurs, sinon ce préchauffage
                // ne sert à rien : Ollama garde une instance par fenêtre, et
                // charger à 32 768 pour interroger ensuite à 8 192 fait payer
                // le rechargement qu'on cherchait précisément à éviter.
                'options'    => ['num_ctx' => $contexte],
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            /* Il faut attendre la fin du chargement. Première version, on
               raccrochait au bout d'une seconde en pariant qu'Ollama
               continuerait de son côté : mesuré, il n'en fait rien — la
               requête coupée ne charge tout simplement pas, et le
               préchauffage était un appel pour rien. C'est donc à l'appelant
               de ne pas faire attendre l'écran (voir `Direct::prechauffer()`),
               pas à ce client de mentir sur ce qu'il a fait.

               Décharger, en revanche, est immédiat. */
            CURLOPT_TIMEOUT        => $secondes === 0 ? 5 : 120,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
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
        int $contexte = self::CONTEXTE,
    ): ?array {
        $charge = [
            'model'    => $modele,
            'messages' => $messages,
            'stream'   => false,
            /* Couper la réflexion des modèles qui en ont une (Qwen3 et
               suivants). Elle est ici doublement fatale : elle consomme
               `num_predict` avant d'écrire un mot — soixante jetons de
               raisonnement, zéro de réponse — et elle dépense le délai qu'on
               vient justement de plafonner. La méthode promet « une phrase,
               tout de suite ou pas du tout » : penser d'abord la contredit.

               Ici et pas chez l'appelant : `Direct` et `Ia` veulent tous deux
               ce comportement, et c'est la nature de `phrase()` qui l'impose,
               pas leur usage. Ignoré sans dommage par les modèles sans
               réflexion. */
            'think'    => false,
            'options'  => [
                'temperature' => $temperature,
                // Borner la génération autant que le délai : sans ça, le modèle
                // part sur un paragraphe et le timeout coupe au milieu d'un mot.
                'num_predict' => $maxJetons,
                // Identique à `repondre()` et `residence()` : voir CONTEXTE.
                'num_ctx'     => $contexte,
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
     * @param callable(): void|null                       $surReflexion
     * @return array{content: string, tool_calls: array, prompt_eval_count: int, eval_count: int, eval_duration: int}
     */
    public function repondre(
        string $modele,
        array $messages,
        callable $surJeton,
        float $temperature = 0.7,
        array $outils = [],
        int $contexte = self::CONTEXTE,
        ?callable $surReflexion = null,
    ): array {
        $contenu = '';
        $appelsOutils = [];
        $reflechi = false;
        $stats = ['prompt_eval_count' => 0, 'eval_count' => 0, 'eval_duration' => 0];

        $charge = [
            'model'    => $modele,
            'messages' => $messages,
            'stream'   => true,
            'options'  => [
                'temperature' => $temperature,
                /* La fenêtre se déclare, elle ne se subit pas : c'est elle qui
                   décide si le modèle tient en VRAM ou déborde en RAM, et le
                   défaut d'Ollama change d'une version à l'autre. Non déclarée,
                   la même machine et le même modèle ne donnaient pas le même
                   débit après une mise à jour du moteur. */
                'num_ctx'     => $contexte,
            ],
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
            CURLOPT_WRITEFUNCTION  => function ($curl, string $bloc) use (&$contenu, &$appelsOutils, &$reflechi, &$stats, $surJeton, $surReflexion): int {
                foreach (explode("\n", $bloc) as $ligne) {
                    $ligne = trim($ligne);
                    if ($ligne === '') {
                        continue;
                    }
                    $obj = json_decode($ligne, true);
                    if (!is_array($obj)) {
                        continue;
                    }

                    /* Un modèle à réflexion streame d'abord dans `thinking`,
                       et `content` reste vide plusieurs secondes. Sans ce
                       signal, aucune phase ne part et l'écran passe pour figé
                       — le cas même que la phase « analyse » devait couvrir.

                       On ne transmet pas le texte, seulement le fait qu'il
                       coule : afficher un modèle qui commente son propre
                       raisonnement, c'est la méta-cognition qu'on a écartée
                       (CLAUDE.md, § Ce qu'on ne recopie pas). */
                    if (!$reflechi && ($obj['message']['thinking'] ?? '') !== '') {
                        $reflechi = true;
                        if ($surReflexion !== null) {
                            $surReflexion();
                        }
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
