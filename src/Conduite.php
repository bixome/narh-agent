<?php
declare(strict_types=1);

/**
 * Les conduites — ce que la veille déclenche toute seule.
 *
 * C'est la règle 6 rendue exécutable, et le dernier morceau du méta-agent. Une
 * conduite n'invente aucune mécanique : elle branche une **commande déjà
 * nommée** (`Ecran::COMMANDES`) sur un seuil, un mot ou une maison. C'est
 * précisément ce que la règle 5 achète — une action qui n'existe que sur un
 * bouton n'aurait rien à brancher ici.
 *
 * ## Pourquoi ceci ne vit pas dans `Collecteur`
 *
 * Règle 4 : la collecte ne pense pas. Le collecteur rend un rapport et ignore
 * qu'un journal existe — c'est ce qui permet de l'appeler du CLI, de l'écran ou
 * de l'API en disant chaque fois d'où l'on vient. Les conduites s'évaluent donc
 * **après** le cycle, au même endroit que `Ecran::journaliserCycle()`, et pour
 * la même raison.
 *
 * ## Le nom était pris, et par du code mort
 *
 * `Base::conduite()` désignait la liste des sujets suivis — sans aucun
 * appelant, `arbre(['statut' => 'suivi'])` la servant déjà. C'était la collision
 * « journal » de `docs/fusion.md` une seconde fois : un concept central du
 * projet portant le nom d'autre chose. Le nom revient ici, à la chose qu'il
 * décrit dans CLAUDE.md.
 *
 * ## Ce qu'une conduite ne fera jamais
 *
 * **Écrire dans la collecte au nom du modèle.** Marquer un groupe est un geste
 * de desk, reproductible et réversible ; ce que dit le modèle s'affiche à côté,
 * jamais dedans (règle 4). `interroger` ouvre donc un tour de conversation — il
 * ne repeint pas le score.
 *
 * **Tirer deux fois sur le même événement.** Un événement reste chaud des
 * heures : sans mémoire, la même commande rejouerait à chaque cycle et la
 * chronologie unique — le seul endroit d'où l'on voit le méta-agent agir —
 * deviendrait illisible au moment précis où elle compte.
 */
final class Conduite
{
    /**
     * Les commandes qui posent un statut sur le groupe.
     *
     * Elles se distinguent des autres sur un point qui compte ici : leur effet
     * est un **état**, pas un acte. Deux fois « suivi » ne fait pas deux
     * suivis, alors que deux briefings font deux réponses de modèle.
     */
    private const MARQUAGES = ['suivi', 'traite', 'ecarte'];

    /**
     * Les commandes qu'une conduite peut jouer.
     *
     * Elles ne sont pas listées ici : `Ecran::COMMANDES` les déclare, avec le
     * reste de ce qu'une commande sait faire. C'est la leçon des `natures` —
     * supposer qu'une commande accepte n'importe quel contexte a produit une
     * famille entière de défauts silencieux. Une commande d'écran (« poser une
     * tuile », « basculer l'antenne ») n'a pas de sens sans navigateur ; la
     * table le dit, et cette classe le fait respecter.
     *
     * @return array<string, string> clé de commande => libellé
     */
    public static function commandes(): array
    {
        $auto = [];
        foreach (Ecran::COMMANDES as $cle => $cmd) {
            if (($cmd[6] ?? '') !== '') {
                $auto[$cle] = $cmd[0];
            }
        }

        return $auto;
    }

    /**
     * Les conduites déclarées, validées.
     *
     * Une conduite invalide est **écartée en le disant**, jamais corrigée en
     * silence : elle vient d'un fichier écrit à la main, et la seule chose pire
     * qu'une conduite qui ne tire pas est une conduite qu'on croit armée.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarees(bool $toutes = false): array
    {
        $fichier = NARH_RACINE . '/config/conduites.php';
        if (!is_file($fichier)) {
            return [];
        }

        $auto = self::commandes();
        $gardees = [];
        $noms = [];

        foreach ((array) require $fichier as $c) {
            $nom = trim((string) ($c['nom'] ?? ''));
            $faire = trim((string) ($c['faire'] ?? ''));
            $quand = is_array($c['quand'] ?? null) ? $c['quand'] : [];

            $refus = match (true) {
                $nom === ''               => 'sans nom',
                isset($noms[$nom])        => 'nom déjà pris',
                !isset($auto[$faire])     => "« $faire » n'est pas une commande déclenchable",
                $quand === []             => 'sans déclencheur — elle tirerait sur tout',
                default                   => '',
            };

            if ($refus !== '') {
                Journal::noter('warn', 'conduite', "conduite écartée ($nom) : $refus");
                continue;
            }

            $noms[$nom] = true;
            $c['actif'] = (bool) ($c['actif'] ?? true);

            if ($toutes || $c['actif']) {
                $gardees[] = $c;
            }
        }

        return $gardees;
    }

    /**
     * Passer la collecte au crible et jouer ce qui doit l'être.
     *
     * `$sec` rend le tour à blanc : on voit ce qui tirerait sans rien écrire ni
     * rien retenir. C'est ce qui permet d'allumer une conduite qui **retire**
     * quelque chose de la vue — écarter — après avoir regardé sa prise, plutôt
     * qu'en le découvrant le lendemain matin dans un desk vidé.
     *
     * @return list<array{nom: string, dit: string, faire: string, groupe: int, titre: string, issue: string}>
     */
    public static function evaluer(Base $base, string $porte, ?int $maintenant = null, bool $sec = false): array
    {
        $maintenant ??= time();
        $conduites = self::declarees();
        if ($conduites === []) {
            return [];
        }

        /* La fenêtre est courte exprès. Une conduite parle du présent : un
           événement de ce matin qu'aucune conduite n'a pris ce matin n'a pas à
           être pris ce soir, il a été vu par quelqu'un entre-temps. Large, la
           fenêtre ferait aussi tirer tout l'arriéré au premier démarrage. */
        $candidats = $base->aConduire($maintenant - 3600, 120);
        $tirs = [];

        /* Ce qu'on vient de marquer pendant ce tour. Deux conduites tombent
           souvent sur le même événement — « alerte confirmée » et « reprise
           large » visent le même sujet par deux chemins — et la seconde
           n'avait alors plus rien à changer. Elle écrivait quand même sa ligne
           de journal : deux traces pour un seul effet, dans le seul endroit qui
           doit rester lisible quand on cherche ce qui s'est passé la nuit.

           `aConduire()` écarte déjà les groupes marqués, mais il a lu la base
           avant la boucle : ce qui est décidé pendant le tour n'y est pas.

           @var array<int, string> */
        $applique = [];

        foreach ($candidats as $g) {
            foreach ($conduites as $c) {
                if (!self::correspond($c['quand'], $g)) {
                    continue;
                }

                $nom = (string) $c['nom'];
                $faire = (string) $c['faire'];
                $groupeId = (int) $g['id'];

                // Sans effet : ni tir, ni trace, ni mémoire. Rien ne s'est
                // passé, et la conduite reste libre de tirer sur autre chose.
                if (($applique[$groupeId] ?? (string) $g['statut']) === $faire) {
                    continue;
                }

                if (self::dejaTire($nom, $groupeId)) {
                    continue;
                }

                if ($sec) {
                    $tirs[] = self::tir($c, $g, 'à blanc');
                    continue;
                }

                /* On retient **avant** d'agir. Une commande qui échoue — Ollama
                   déchargé, groupe disparu entre deux requêtes — ne doit pas
                   revenir au cycle suivant : elle échouerait pareil, et
                   payerait son délai d'attente à chaque tour de boucle. Ce qui
                   s'est passé se lit dans la chronologie, pas dans un compteur
                   de tentatives. */
                self::retenir($nom, $groupeId, $maintenant, (string) $g['titre']);

                $issue = self::jouer($base, $faire, $g, $porte, $maintenant);
                $tirs[] = self::tir($c, $g, $issue);

                if ($issue === 'fait' && in_array($faire, self::MARQUAGES, true)) {
                    $applique[$groupeId] = $faire;
                }

                Journal::noter(
                    $issue === 'fait' ? 'ok' : 'warn',
                    'conduite',
                    sprintf('%s → %s : %s', $nom, $faire, Util::tronquer((string) $g['titre'], 60))
                        . ($issue === 'fait' ? '' : " — $issue"),
                );
            }
        }

        return $tirs;
    }

    /**
     * Le déclencheur : toutes les clés présentes doivent être vraies.
     *
     * Un ET et pas un OU — une conduite se lit « niveau alerte **et** trois
     * maisons ». En OU, `mots` suffirait à faire tirer sur un titre isolé, ce
     * que le score lui-même refuse de faire (`Alerte::PLAFOND_LEXIQUE`).
     *
     * @param array<string, mixed> $quand
     * @param array<string, mixed> $g
     */
    private static function correspond(array $quand, array $g): bool
    {
        foreach ($quand as $cle => $attendu) {
            $vrai = match ($cle) {
                'niveau'   => (int) $g['niveau'] >= (int) $attendu,
                // `sources` porte le nombre de **maisons**, pas de flux : c'est
                // `recalculerGroupe()` qui l'y met, après regroupement.
                'maisons'  => (int) $g['sources'] >= (int) $attendu,
                /* Le plancher a besoin d'un plafond, et pour une raison qui ne
                   se devine pas : `sources` ne compte ni les agrégateurs ni le
                   web social — ils ne confirment rien. Un billet social que
                   personne ne reprend vaut donc **zéro** maison, pas une. Écrit
                   `maisons: 1`, ce qui semblait dire « au moins lui-même », la
                   conduite ne tirait jamais. Mesuré : zéro prise sur trois
                   jours, et ce silence-là passe pour un réglage qui marche. */
                'maisons_max' => (int) $g['sources'] <= (int) $attendu,
                'rubrique' => (string) ($g['rubrique'] ?? '') === (string) $attendu,
                'mots'     => self::contient((string) $g['titre'], (array) $attendu),
                // Une clé inconnue est une faute de frappe dans un fichier écrit
                // à la main. La traiter en « vrai » armerait la conduite sur un
                // critère que personne n'a voulu.
                default    => false,
            };

            if (!$vrai) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $mots */
    private static function contient(string $titre, array $mots): bool
    {
        // Normalisé des deux côtés : le lexique d'`Alerte` cherche déjà sans
        // accent ni ponctuation, et une conduite écrite « décès » doit prendre
        // « deces » comme le reste du moteur.
        $plat = ' ' . Util::normaliser($titre) . ' ';

        foreach ($mots as $mot) {
            $m = Util::normaliser((string) $mot);
            if ($m !== '' && str_contains($plat, ' ' . $m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jouer la commande. Renvoie « fait » ou la raison du refus.
     *
     * @param array<string, mixed> $g
     */
    private static function jouer(Base $base, string $faire, array $g, string $porte, int $maintenant): string
    {
        if (in_array($faire, self::MARQUAGES, true)) {
            // Le statut porte sur le groupe, jamais sur la dépêche : on suit un
            // sujet, et les reprises qui arrivent ensuite en héritent.
            return $base->marquer((int) $g['id'], $faire, $maintenant) ? 'fait' : 'groupe introuvable';
        }

        if ($faire === 'interroger') {
            return self::interroger($base, $g, $porte);
        }

        return 'commande sans effet hors écran';
    }

    /**
     * La boucle dans son sens plein : ce qui arrive déclenche ce qui pense.
     *
     * **Réservée au démon.** Le modèle met des secondes à répondre, et l'écran
     * évalue les conduites pendant qu'il compose une page : une réponse
     * d'Ollama dans ce chemin ajouterait cinq secondes au chargement, au moment
     * précis où l'on attend. C'est la règle qui écarte déjà la lecture
     * d'article de la réponse, appliquée au même endroit et pour le même prix.
     *
     * Sans démon, ces conduites-là ne tirent donc pas — et rien ne les retient,
     * puisqu'elles ne sont pas réclamées : elles tireront au premier
     * `php cli.php --veille`.
     *
     * @param array<string, mixed> $g
     */
    private static function interroger(Base $base, array $g, string $porte): string
    {
        if ($porte !== 'démon') {
            return 'réservé au démon';
        }

        $ollama = new Ollama((string) narh_reglage('ollama')['url']);
        if (!$ollama->disponible()) {
            return 'Ollama injoignable';
        }

        $filId = self::fil();
        $depeche = (int) ($g['article_id'] ?? 0);

        /* La dépêche entre au dossier avant la question, sous le rôle `outil` —
           la même mécanique que `Agent::ancrer()`, y compris la consommation
           après le tour. Formulée comme un apport et non comme une question :
           présentée en question, elle devient le sujet et le modèle y répond au
           lieu de répondre à ce qu'on lui demande. */
        $a = $depeche > 0 ? $base->article($depeche) : null;
        if ($a !== null) {
            Memoire::messageAjouter($filId, 'outil', sprintf(
                "[Dépêche de la veille, versée au dossier — %s, %s]\n%s\n%s",
                (string) $a['source_nom'],
                date('d/m/Y H:i', (int) $a['date_tri']),
                (string) $a['titre'],
                trim((string) ($a['resume'] ?? '')),
            ), [[
                'outil'     => 'veille',
                'arguments' => ['depeche' => $depeche],
                'ok'        => true,
                'resultat'  => [$a],
            ]]);
        }

        $question = sprintf(
            "Un événement vient d'être confirmé par %d rédactions : « %s ». "
                . "En trois phrases : ce qu'on sait, ce qu'on ne sait pas encore, "
                . 'et ce qu\'il faut surveiller dans l\'heure.',
            (int) $g['sources'],
            (string) $g['titre'],
        );
        Memoire::messageAjouter($filId, 'user', $question);

        try {
            $debut = microtime(true);
            $resultat = Agent::repondre(
                $ollama,
                Agent::reglages(),
                array_slice(Memoire::messages($filId), -20),
                // Personne ne regarde : il n'y a pas de trame à alimenter. Les
                // étapes restent dans le tour, qui les rendra à l'affichage.
                static function (string $type, array $donnees): void {
                },
            );

            Memoire::messageAjouter(
                $filId,
                'assistant',
                $resultat['content'],
                $resultat['etapes'],
                null,
                $resultat['eval_count'],
                $resultat['tuiles'] ?? [],
                $resultat['contexte'] ?? 0,
            );
            Memoire::fermerOutils($filId);

            Journal::noter(
                'ok',
                'conduite',
                sprintf('briefing : %d jetons', (int) $resultat['eval_count']),
                (int) round((microtime(true) - $debut) * 1000),
            );

            return 'fait';
        } catch (Throwable $e) {
            return 'modèle : ' . $e->getMessage();
        }
    }

    /**
     * Le fil où l'agent parle sans qu'on lui demande.
     *
     * Un fil dédié, et non celui qu'on a sous les yeux : un briefing de 4 h 30
     * qui s'insère au milieu d'une conversation de la veille en fausse le
     * contexte — le modèle répondrait au réveil sur le sujet de la nuit. Il se
     * relit par `/memoire`, comme les autres.
     *
     * Recréé s'il a été oublié : « oublier le fil » est une commande offerte,
     * et elle ne doit pas casser une conduite.
     */
    private static function fil(): int
    {
        $st = self::pdo()->prepare("SELECT valeur FROM conduite_etat WHERE cle = 'fil'");
        $st->execute();
        $id = (int) $st->fetchColumn();

        if ($id > 0 && Memoire::filExiste($id)) {
            return $id;
        }

        $id = Memoire::filCreer();
        self::pdo()
            ->prepare("INSERT OR REPLACE INTO conduite_etat (cle, valeur) VALUES ('fil', ?)")
            ->execute([(string) $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $g
     * @return array{nom: string, dit: string, faire: string, groupe: int, titre: string, issue: string}
     */
    private static function tir(array $c, array $g, string $issue): array
    {
        return [
            'nom'    => (string) $c['nom'],
            'dit'    => (string) ($c['dit'] ?? $c['nom']),
            'faire'  => (string) $c['faire'],
            'groupe' => (int) $g['id'],
            'titre'  => (string) $g['titre'],
            'issue'  => $issue,
        ];
    }

    private static function dejaTire(string $nom, int $groupeId): bool
    {
        $st = self::pdo()->prepare('SELECT 1 FROM conduite_vu WHERE nom = ? AND groupe_id = ?');
        $st->execute([$nom, $groupeId]);

        return (bool) $st->fetchColumn();
    }

    private static function retenir(string $nom, int $groupeId, int $quand, string $titre): void
    {
        self::pdo()->prepare(
            'INSERT OR REPLACE INTO conduite_vu (nom, groupe_id, quand, titre) VALUES (?, ?, ?, ?)'
        )->execute([$nom, $groupeId, $quand, mb_substr($titre, 0, 200)]);
    }

    /**
     * Ce qui a tiré, le plus récent d'abord — de quoi remplir la tuile.
     *
     * Rendu sous la forme d'un fait de la chronologie : une conduite qui agit
     * **est** un fait, et la règle 7 veut qu'elle se lise comme les autres.
     * `Piece::fait()` la met alors en ligne sans gabarit de plus.
     *
     * @return list<array{quand: int, niveau: string, source: string, message: string, duree: ?int}>
     */
    public static function tirs(int $limite = 30): array
    {
        try {
            $st = self::pdo()->prepare(
                'SELECT nom, groupe_id, quand, titre FROM conduite_vu ORDER BY quand DESC, rowid DESC LIMIT ?'
            );
            $st->execute([$limite]);

            return array_map(static fn (array $r): array => [
                'quand'   => (int) $r['quand'],
                'niveau'  => 'ok',
                'source'  => (string) $r['nom'],
                'message' => (string) $r['titre'],
                'duree'   => null,
            ], $st->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /** Rogner la queue : la mémoire d'une conduite ne sert plus une fois l'événement purgé. */
    public static function oublier(int $avant): int
    {
        try {
            $st = self::pdo()->prepare('DELETE FROM conduite_vu WHERE quand < ?');
            $st->execute([$avant]);

            return $st->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    private static bool $prete = false;

    private static function pdo(): PDO
    {
        $pdo = Db::narh();

        if (!self::$prete) {
            /* Dans `narh.sqlite` et non dans la collecte : c'est une trace de ce
               que NARH a fait, pas un attribut de la dépêche. La collecte est
               reconstructible en un cycle ; ce qui a été déclenché, non
               (règle 3, et la même raison que le journal). */
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS conduite_vu (
                    nom       TEXT    NOT NULL,
                    groupe_id INTEGER NOT NULL,
                    quand     INTEGER NOT NULL,
                    titre     TEXT    NOT NULL DEFAULT '',
                    PRIMARY KEY (nom, groupe_id)
                );

                CREATE INDEX IF NOT EXISTS idx_conduite_vu_quand ON conduite_vu(quand DESC);

                CREATE TABLE IF NOT EXISTS conduite_etat (
                    cle    TEXT PRIMARY KEY,
                    valeur TEXT NOT NULL
                );
                SQL);
            self::$prete = true;
        }

        return $pdo;
    }
}
