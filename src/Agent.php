<?php
declare(strict_types=1);

/**
 * La voix — la boucle question → (outil)* → réponse.
 *
 * Portée depuis otow-agent. Deux écarts assumés par rapport à l'original :
 *
 * - la conversation vit dans `Memoire` (narh.sqlite), pas `$_SESSION` ;
 * - chaque tour entre dans `Journal`, la chronologie commune à la collecte
 *   (CLAUDE.md, règle 7) — otow-agent tenait la sienne à part, dans un fichier.
 *
 * La méta-cognition (vraisemblance des jetons, ancrage) d'otow-agent n'est pas
 * portée : elle dépendait des logprobs d'Ollama, un axe de mesure à part
 * entière, hors du périmètre de cette phase. Rien n'empêche de la reprendre
 * plus tard — `Ollama::repondre()` sait déjà tout streamer.
 */
final class Agent
{
    public const REGLAGES_FICHIER = NARH_VAR . '/agent.json';

    /** @return array<string, mixed> */
    public static function reglagesDefaut(): array
    {
        return [
            'modele'         => (string) narh_reglage('ollama')['modele'],
            'temperature'    => (float) narh_reglage('ollama')['temperature'],
            'contexte'       => (int) (narh_reglage('ollama')['contexte'] ?? 8192),
            /* La tenue de la maison. Trois blocs, et l'ordre compte : ce qu'il
               est, ce qu'il ne doit pas faire, comment il écrit.

               Les interdits sont formulés en négatif et nommément — « ne dis
               pas », pas « sois prudent ». Mesuré sur la voix du direct : à qui
               on demande de « commenter avec prudence », un modèle local ajoute
               des faits absents de sa matière (« les équipes se tiennent
               prêtes »), parce que commenter appelle du contenu. La consigne
               qui tient est celle qui borne la source, pas celle qui demande un
               état d'esprit.

               Court volontairement : chaque phrase se paie en contexte à tous
               les tours, et un petit modèle noie une consigne longue. */
            'prompt_systeme' => 'Tu es NARH, un agent local exécuté via Ollama, intégré à une console de veille '
                . "d'actualité. Tu travailles pour une rédaction : ce que tu affirmes doit pouvoir être vérifié.\n\n"
                . "Sur les faits :\n"
                . "- Pour toute question d'actualité, utilise rechercher_actualites plutôt que tes connaissances "
                . "générales : ta mémoire est datée, la veille ne l'est pas.\n"
                . "- N'avance aucun chiffre, nom, lieu, date ni citation que tes sources ne portent pas. "
                . "Si la matière manque, dis ce qui manque — c'est une réponse, pas un échec.\n"
                . "- Attribue et date : « selon Le Monde, mardi ». Une information sans origine n'a pas de valeur "
                . "dans une rédaction.\n"
                . "- Sépare ce qui est établi de ce qui est annoncé, allégué ou probable, et garde le "
                . "conditionnel pour ce qui n'est pas confirmé.\n"
                . "- Une reprise par plusieurs rédactions est un signal de confirmation, pas une preuve : "
                . "dis combien de rédactions portent le fait.\n\n"
                . "Sur la forme :\n"
                /* Nommer les caractères, pas « pas de markdown lourd » : à cette
                   consigne-là, le modèle a rendu « **250 hectares** » et des
                   citations en italiques. L'écran est une console monospace qui
                   ne rend aucun balisage — les astérisques s'y affichent telles
                   quelles, au milieu du texte. */
                . "- Français, et texte brut : jamais d'astérisque, de dièse ni de souligné pour mettre en "
                . "forme. La console n'interprète aucun balisage et les afficherait tels quels.\n"
                . "- Concis et direct : l'essentiel d'abord, le détail ensuite.\n"
                . "- Ton neutre de dépêche. Pas de formule d'accroche, pas d'emphase, pas d'adjectif "
                . "d'appréciation. Tu rapportes, tu ne commentes pas.\n"
                . "- Les autres outils, seulement quand ils rendent la réponse plus fiable qu'une estimation.",
            /* Décoché, le modèle ne se voit plus proposer d'outils. Mesuré chez
               otow-agent : llama3.2:3b appelle un outil par réflexe dès qu'on
               lui en présente un, même sur un « merci ». */
            'outils_auto'    => true,
        ];
    }

    /** @return array<string, mixed> */
    public static function reglages(): array
    {
        if (!is_file(self::REGLAGES_FICHIER)) {
            return self::reglagesDefaut();
        }
        $data = json_decode((string) file_get_contents(self::REGLAGES_FICHIER), true);

        return is_array($data) ? array_merge(self::reglagesDefaut(), $data) : self::reglagesDefaut();
    }

    public static function reglagesSauver(array $reglages): void
    {
        file_put_contents(
            self::REGLAGES_FICHIER,
            json_encode($reglages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /* ---- Le fil courant ----------------------------------------------------
       La session ne retient que l'identifiant du fil ouvert ; son contenu vit
       en base (CLAUDE.md, § Ce qu'on ne recopie pas). */

    /* Le fil résolu, pour la durée de la requête et pas au-delà.

       Il existe pour que refermer la session serve à quelque chose. PHP tient
       le fichier de session verrouillé jusqu'à la fin du script : tant que
       `api/chat.php` streame, aucune autre requête du même navigateur ne
       passe — mesuré, l'écran entier se met en file derrière la génération.
       On rend donc la main dès que la session n'a plus rien à écrire.

       Sans ce souvenir, le premier `filId()` qui suit rouvrirait la session —
       donc reprendrait le verrou — pour relire une valeur déjà connue.
       `api/chat.php` relit le fil trois fois **après** avoir rendu la main. */
    private static ?int $filCourant = null;

    public static function filId(bool $creer = false): int
    {
        /* Un 0 en mémoire vaut réponse tant qu'on ne demande pas de créer :
           « pas de fil » est un fait acquis, et le redemander à la session
           coûterait le verrou pour la même réponse. */
        if (self::$filCourant !== null && (self::$filCourant > 0 || !$creer)) {
            return self::$filCourant;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $id = (int) ($_SESSION['fil_id'] ?? 0);
        if ($id > 0 && Memoire::filExiste($id)) {
            return self::$filCourant = $id;
        }
        if (!$creer) {
            return self::$filCourant = 0;
        }

        $id = Memoire::filCreer();
        $_SESSION['fil_id'] = $id;

        return self::$filCourant = $id;
    }

    public static function filOuvrir(int $id): bool
    {
        if (!Memoire::filExiste($id)) {
            return false;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['fil_id'] = $id;
        self::$filCourant = $id;

        return true;
    }

    public static function filNeuf(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['fil_id']);
        // À null et non à 0 : le prochain `filId(true)` doit pouvoir créer.
        self::$filCourant = null;
    }

    /**
     * Rendre le verrou de session, la lecture du fil étant faite.
     *
     * À appeler dès qu'une route n'a plus rien à écrire en session — et
     * toujours **avant** ce qui prend du temps : un stream, un appel au
     * modèle, un rendu. Ce qui suit continue de lire le fil par
     * `filId()`, qui s'en souvient et ne rouvre rien.
     *
     * Sans effet si la session n'a jamais été ouverte : les routes qui n'en
     * veulent pas n'ont pas à s'en soucier.
     */
    public static function filRendreLaMain(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** @return list<array<string, mixed>> */
    public static function tours(): array
    {
        $id = self::filId();

        return $id > 0 ? Memoire::messages($id) : [];
    }

    /**
     * @param list<array<string, mixed>> $etapes
     * @param list<Tuile>                $tuiles
     */
    public static function tourAjouter(
        string $role,
        string $contenu,
        array $etapes = [],
        ?array $bilan = null,
        int $jetons = 0,
        array $tuiles = [],
        int $contexte = 0,
    ): void {
        Memoire::messageAjouter(self::filId(true), $role, $contenu, $etapes, $bilan, $jetons, $tuiles, $contexte);
    }

    /**
     * Ancrer la conversation sur une dépêche — le pont, sens veille → agent.
     *
     * La dépêche entre dans le fil comme **pièce versée au dossier**, sous le
     * rôle `outil` : elle emprunte donc la mécanique déjà en place, y compris
     * la consommation après le tour (`Memoire::fermerOutils`). Sans cela elle
     * resterait dans le contexte à chaque tour suivant et ramènerait le modèle
     * sur ce sujet quoi qu'on lui demande ensuite.
     *
     * Formulée comme un apport, pas comme une question : présentée en question,
     * la dépêche devenait le sujet et le modèle y répondait au lieu de répondre
     * à ce qu'on lui demandait vraiment.
     */
    public static function ancrer(int $depecheId): bool
    {
        $base = new Base((string) narh_reglage('base_veille'));
        $a = $base->article($depecheId);
        if ($a === null) {
            return false;
        }

        $texte = sprintf(
            "[Dépêche de la veille, versée au dossier — %s, %s]\n%s\n%s",
            (string) $a['source_nom'],
            date('d/m/Y H:i', (int) $a['date_tri']),
            (string) $a['titre'],
            trim((string) ($a['resume'] ?? '')),
        );

        // L'étape porte la dépêche entière : c'est elle qui fera la ligne
        // cliquable sous le tour, comme pour un résultat d'outil.
        self::tourAjouter('outil', $texte, [[
            'outil'    => 'veille',
            'arguments' => ['depeche' => $depecheId],
            'ok'       => true,
            'resultat' => [$a],
        ]]);

        return true;
    }

    /**
     * Faut-il proposer les outils pour ce tour ?
     *
     * Mesuré chez otow-agent : un simple « Merci, c'est noté. » suffisait à
     * déclencher une recherche d'actualités. On décide donc à sa place dans les
     * cas où la réponse est évidente.
     *
     * @param list<array{role: string, content: string}> $historique
     * @return list<array<string, mixed>>
     */
    private static function outilsPour(array $reglages, array $historique): array
    {
        if (empty($reglages['outils_auto'])) {
            return [];
        }

        /* Une pièce vient d'être versée au dossier — une dépêche ancrée depuis
           la veille : la matière est déjà sur la table. Lui rouvrir le
           catalogue le renvoyait chercher ailleurs. Mesuré : sur « résume cette
           dépêche », le modèle rappelait `rechercher_actualites` et répondait
           sur un tout autre sujet que celui qu'on lui avait tendu. */
        foreach ($historique as $tour) {
            if (($tour['role'] ?? '') === 'outil' && empty($tour['consomme'])) {
                return [];
            }
        }

        $dernier = '';
        foreach (array_reverse($historique) as $tour) {
            if (($tour['role'] ?? '') === 'user') {
                $dernier = trim((string) $tour['content']);
                break;
            }
        }

        // « ok », « merci », « très bien » : des accusés de réception, pas des
        // demandes. Aucun outil n'y répondrait utilement.
        if (mb_strlen($dernier) < 12) {
            return [];
        }

        return Outils::definitions();
    }

    /**
     * Ce qui part réellement chez Ollama : prompt système, puis l'historique.
     *
     * @param list<array<string, mixed>> $historique
     * @return list<array{role: string, content: string}>
     */
    private static function messages(array $reglages, array $historique): array
    {
        $messages = [['role' => 'system', 'content' => $reglages['prompt_systeme']]];

        foreach ($historique as $tour) {
            // Un résultat d'outil déjà consommé sort du contexte : il a servi
            // au tour qui suivait son exécution. Rejoué indéfiniment, il
            // maintenait le sujet chaud — un « merci » suffisait à relancer une
            // recherche. Il reste visible dans la conversation rendue.
            if (($tour['role'] ?? '') === 'outil' && !empty($tour['consomme'])) {
                continue;
            }
            $messages[] = ['role' => $tour['role'], 'content' => $tour['content']];
        }

        return $messages;
    }

    /**
     * La boucle. Émet chaque événement via $emettre pour que l'appelant streame
     * (SSE) et journalise ; renvoie la réponse finale et la trace des étapes.
     *
     * @param list<array<string, mixed>> $historique
     * @param callable(string $type, array $donnees): void $emettre
     * @return array{content: string, etapes: array, eval_count: int, tuiles: list<Tuile>}
     */
    public static function repondre(Ollama $ollama, array $reglages, array $historique, callable $emettre): array
    {
        $messages = self::messages($reglages, $historique);
        $outils = self::outilsPour($reglages, $historique);
        $emettre('outils', ['offerts' => count($outils)]);

        /* Les tuiles que l'agent décide de poser. Il n'en demande aucune au
           modèle — on ne lui fait pas choisir un composant d'interface, il
           l'écorcherait comme il écorche les URL. Elles se déduisent de ce
           qu'il a réellement consulté : chercher dans la veille, c'est avoir
           besoin de la montrer. */
        $tuiles = [];

        $etapes = [];
        $stats = ['eval_count' => 0, 'eval_duration' => 0, 'contexte' => 0];
        $maxIterations = 4;
        $signatures = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            // Entre l'envoi et le premier jeton, Ollama charge éventuellement le
            // modèle : plusieurs secondes pendant lesquelles rien n'arrive. Sans
            // phase annoncée, l'écran passe pour figé.
            $emettre('phase', ['phase' => $iteration === 0 ? 'analyse' : 'reprise']);
            $premier = true;

            $resultat = $ollama->repondre(
                $reglages['modele'],
                $messages,
                function (string $jeton) use ($emettre, &$premier): void {
                    if ($premier) {
                        $premier = false;
                        $emettre('phase', ['phase' => 'generation']);
                    }
                    $emettre('jeton', ['texte' => $jeton]);
                },
                (float) $reglages['temperature'],
                $outils,
                (int) $reglages['contexte'],
                // Le modèle réfléchit avant d'écrire : c'est encore de
                // l'analyse, et c'est la seule chose qu'on puisse dire pendant
                // ces secondes-là. Le contenu de sa réflexion ne sort pas.
                static fn (): mixed => $emettre('phase', ['phase' => 'analyse']),
            );
            $stats['eval_count'] += $resultat['eval_count'];
            $stats['eval_duration'] += $resultat['eval_duration'];

            /* Les jetons relus ne s'additionnent pas : le contexte est
               reconstruit à chaque itération, et c'est la dernière — la plus
               chargée, puisqu'elle porte les résultats d'outils — qui dit
               vraiment ce qu'occupe la fenêtre. */
            $stats['contexte'] = max($stats['contexte'], (int) $resultat['prompt_eval_count']);

            if (empty($resultat['tool_calls'])) {
                $emettre('phase', ['phase' => 'repos']);

                return [
                    'content'    => $resultat['content'],
                    'etapes'     => $etapes,
                    'eval_count' => $stats['eval_count'],
                    'contexte'   => $stats['contexte'],
                    'tuiles'     => $tuiles,
                ];
            }

            $emettre('phase', ['phase' => 'outil']);

            // `arguments` vide doit rester un objet JSON (`{}`) au retour vers
            // Ollama : un tableau PHP vide se réencode en `[]`, qu'Ollama refuse.
            $appelsNormalises = array_map(static function (array $appel): array {
                if (empty($appel['function']['arguments'])) {
                    $appel['function']['arguments'] = new stdClass();
                }

                return $appel;
            }, $resultat['tool_calls']);

            $messages[] = ['role' => 'assistant', 'content' => $resultat['content'], 'tool_calls' => $appelsNormalises];

            foreach ($resultat['tool_calls'] as $appel) {
                $nom = (string) ($appel['function']['name'] ?? '');
                $args = $appel['function']['arguments'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?? [];
                }

                $emettre('outil_appel', ['nom' => $nom, 'arguments' => $args]);
                $sortie = Outils::executer($nom, (array) $args);
                $emettre('outil_resultat', ['nom' => $nom, 'ok' => $sortie['ok'], 'resultat' => $sortie['resultat']]);

                $signature = $nom . '|' . json_encode($args, JSON_UNESCAPED_UNICODE);
                if (isset($signatures[$signature])) {
                    // Impasse : le même appel refait à l'identique ne rendra
                    // pas autre chose. Le journal le voit, la conversation
                    // continue quand même — au modèle de conclure.
                    Journal::noter('warn', 'agent', "appel répété à l'identique : $nom");
                }
                $signatures[$signature] = true;

                $resume = Outils::resumer($nom, $sortie['resultat']);
                $etapes[] = ['outil' => $nom, 'arguments' => $args, 'ok' => $sortie['ok'], 'resultat' => $sortie['resultat']];
                $messages[] = ['role' => 'tool', 'content' => $resume];

                /* L'agent a consulté quelque chose : il pose la tuile qui le
                   montre, quand cet outil en a une. C'est ce qui rend la
                   réponse vérifiable — on lit le fil d'où elle sort, sans
                   changer d'écran.

                   Le choix de la tuile appartient à `Outils` : c'est lui qui
                   sait ce qu'un outil rend, et l'agent n'a pas à connaître le
                   catalogue pour l'afficher. */
                if ($sortie['ok'] && ($tuile = Outils::tuilePour($nom, (array) $args)) !== null) {
                    $tuiles[] = $tuile;
                }
            }
        }

        $emettre('error', ['message' => "Trop d'appels d'outils enchaînés, réponse interrompue."]);

        return [
            'content'    => '',
            'etapes'     => $etapes,
            'eval_count' => $stats['eval_count'],
            'contexte'   => $stats['contexte'],
            'tuiles'     => $tuiles,
        ];
    }
}
