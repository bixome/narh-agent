<?php
declare(strict_types=1);

/**
 * La base — SQLite, par PDO, requêtes préparées.
 *
 * Un fichier dans var/, aucun serveur à lancer. Le mode WAL permet à l'écran de
 * lire pendant que le collecteur écrit : sans lui, un cycle bloquerait chaque
 * sondage du navigateur pendant sa durée.
 *
 * Trois tables et un index :
 *
 * - `source`  : l'état du relevé (ETag, cadence, échecs) — une ligne par flux ;
 * - `groupe`  : un événement, tel que plusieurs rédactions l'ont titré ;
 * - `article` : une dépêche, rattachée à un groupe ;
 * - `meta`    : les compteurs du dernier cycle, le dernier commentaire de pic.
 *
 * `article.id` croît avec l'arrivée : c'est le curseur du sondage incrémental.
 * `article.date_tri` porte la date de publication, souvent absente ou fausse
 * dans les flux — d'où deux tris possibles à l'écran, et deux colonnes.
 */
final class Base
{
    private PDO $pdo;

    public function __construct(string $chemin)
    {
        $neuve = !is_file($chemin);

        $this->pdo = new PDO('sqlite:' . $chemin, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        if ($neuve || !$this->tableExiste('article')) {
            $this->creer();
        }
        $this->migrer();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function tableExiste(string $nom): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$nom]);

        return (bool) $st->fetchColumn();
    }

    private function creer(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS source (
                id       TEXT PRIMARY KEY,
                nom      TEXT    NOT NULL,
                url      TEXT    NOT NULL,
                rubrique TEXT    NOT NULL DEFAULT 'une',
                cadence  INTEGER NOT NULL DEFAULT 90,
                poids    REAL    NOT NULL DEFAULT 1.0,
                maison   TEXT    NOT NULL DEFAULT '',
                rang     TEXT    NOT NULL DEFAULT 'redaction',
                actif    INTEGER NOT NULL DEFAULT 1,
                etag     TEXT,
                modifie  TEXT,
                essai    INTEGER NOT NULL DEFAULT 0,
                succes   INTEGER NOT NULL DEFAULT 0,
                code     INTEGER NOT NULL DEFAULT 0,
                ms       INTEGER NOT NULL DEFAULT 0,
                etat     TEXT    NOT NULL DEFAULT 'neuf',
                erreur   TEXT,
                echecs   INTEGER NOT NULL DEFAULT 0,
                recus    INTEGER NOT NULL DEFAULT 0,
                total    INTEGER NOT NULL DEFAULT 0,
                etat_change_le INTEGER
            );

            CREATE TABLE IF NOT EXISTS groupe (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                titre     TEXT    NOT NULL,
                jetons    TEXT    NOT NULL,
                premier   INTEGER NOT NULL,
                dernier   INTEGER NOT NULL,
                sources   INTEGER NOT NULL DEFAULT 1,
                articles  INTEGER NOT NULL DEFAULT 1,
                lexique   INTEGER NOT NULL DEFAULT 0,
                score     INTEGER NOT NULL DEFAULT 0,
                niveau    INTEGER NOT NULL DEFAULT 0,
                motifs    TEXT    NOT NULL DEFAULT '',
                fils      INTEGER NOT NULL DEFAULT 0,
                statut    TEXT    NOT NULL DEFAULT '',
                statut_maj INTEGER,
                vu_dernier INTEGER,
                ia_niveau INTEGER,
                ia_motif  TEXT,
                ia_maj    INTEGER
            );

            CREATE TABLE IF NOT EXISTS article (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                cle       TEXT    NOT NULL UNIQUE,
                source_id TEXT    NOT NULL REFERENCES source(id) ON DELETE CASCADE,
                groupe_id INTEGER REFERENCES groupe(id) ON DELETE SET NULL,
                titre     TEXT    NOT NULL,
                lien      TEXT    NOT NULL,
                resume    TEXT    NOT NULL DEFAULT '',
                publie    INTEGER,
                vu        INTEGER NOT NULL,
                date_tri  INTEGER NOT NULL,
                maj       INTEGER NOT NULL,
                lexique   INTEGER NOT NULL DEFAULT 0,
                score     INTEGER NOT NULL DEFAULT 0,
                niveau    INTEGER NOT NULL DEFAULT 0,
                motifs    TEXT    NOT NULL DEFAULT '',
                jetons    TEXT    NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS meta (
                cle    TEXT PRIMARY KEY,
                valeur TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_article_tri    ON article(date_tri DESC);
            CREATE INDEX IF NOT EXISTS idx_article_vu     ON article(vu DESC);
            CREATE INDEX IF NOT EXISTS idx_article_groupe ON article(groupe_id);
            CREATE INDEX IF NOT EXISTS idx_article_niveau ON article(niveau DESC, id DESC);
            CREATE INDEX IF NOT EXISTS idx_article_maj    ON article(maj);
            CREATE INDEX IF NOT EXISTS idx_groupe_dernier ON groupe(dernier DESC);
            SQL);
    }

    /**
     * Les colonnes ajoutées après coup.
     *
     * `creer()` ne passe que sur une base neuve : une base déjà remplie ne
     * verrait jamais une colonne nouvelle. Trois `ALTER TABLE` valent mieux
     * ici qu'un système de migrations — il n'y a rien d'autre à migrer.
     */
    private function migrer(): void
    {
        $this->ajouterColonnes('source', [
            'maison' => "TEXT NOT NULL DEFAULT ''",
            'rang'   => "TEXT NOT NULL DEFAULT 'redaction'",
            // Écrite seulement au franchissement de echecs_morte, dans un
            // sens ou dans l'autre — voir Collecteur::transition(). C'est ce
            // qui date une entrée « flux » du journal ; sans elle, une source
            // morte n'a pas d'instant à afficher, juste un état.
            'etat_change_le' => 'INTEGER',
        ]);

        $this->ajouterColonnes('groupe', [
            'ia_niveau'  => 'INTEGER',
            'ia_motif'   => 'TEXT',
            'ia_maj'     => 'INTEGER',
            'fils'       => 'INTEGER NOT NULL DEFAULT 0',
            'statut'     => "TEXT NOT NULL DEFAULT ''",
            'statut_maj' => 'INTEGER',
            'vu_dernier' => 'INTEGER',
        ]);
    }

    /** @param array<string, string> $colonnes nom => type SQL */
    private function ajouterColonnes(string $table, array $colonnes): void
    {
        $st = $this->pdo->query("PRAGMA table_info($table)");
        $presentes = array_column($st->fetchAll(), 'name');

        foreach ($colonnes as $nom => $type) {
            if (!in_array($nom, $presentes, true)) {
                $this->pdo->exec("ALTER TABLE $table ADD COLUMN $nom $type");
            }
        }
    }

    /* ---- Sources --------------------------------------------------------- */

    /**
     * Aligne la table sur config/sources.php. Une source retirée du fichier est
     * désactivée, jamais supprimée : ses articles restent lisibles.
     *
     * @param list<array<string, mixed>> $sources
     */
    public function synchroniser(array $sources): void
    {
        $connus = [];
        $ins = $this->pdo->prepare(
            'INSERT INTO source (id, nom, url, rubrique, cadence, poids, maison, rang, actif)
             VALUES (:id, :nom, :url, :rubrique, :cadence, :poids, :maison, :rang, :actif)
             ON CONFLICT(id) DO UPDATE SET
                nom = :nom, url = :url, rubrique = :rubrique,
                cadence = :cadence, poids = :poids, maison = :maison, rang = :rang,
                actif = :actif'
        );

        foreach ($sources as $s) {
            $connus[] = $s['id'];
            $ins->execute([
                'id'       => $s['id'],
                'nom'      => $s['nom'],
                'url'      => $s['url'],
                'rubrique' => $s['rubrique'] ?? 'une',
                'cadence'  => (int) ($s['cadence'] ?? narh_reglage('cadence', 90)),
                'poids'    => (float) ($s['poids'] ?? 1.0),
                // Sans maison déclarée, le flux est sa propre rédaction.
                'maison'   => (string) ($s['maison'] ?? $s['id']),
                'rang'     => (string) ($s['rang'] ?? 'redaction'),
                'actif'    => ($s['actif'] ?? true) ? 1 : 0,
            ]);
        }

        if ($connus !== []) {
            $trous = implode(',', array_fill(0, count($connus), '?'));
            $this->pdo->prepare("UPDATE source SET actif = 0 WHERE id NOT IN ($trous)")->execute($connus);
        }
    }

    /**
     * Les sources dont la cadence est échue. Une source en échec voit son
     * intervalle doubler à chaque échec (plafonné) : un flux mort ne consomme
     * pas un aller-retour toutes les 60 secondes.
     *
     * @return list<array<string, mixed>>
     */
    public function sourcesDues(int $maintenant, bool $tout = false): array
    {
        if ($tout) {
            $st = $this->pdo->query('SELECT * FROM source WHERE actif = 1 ORDER BY cadence');

            return $st !== false ? $st->fetchAll() : [];
        }

        $st = $this->pdo->query('SELECT * FROM source WHERE actif = 1');
        $dues = [];

        foreach ($st !== false ? $st->fetchAll() : [] as $s) {
            $recul = min(
                (int) $s['cadence'] * (2 ** min((int) $s['echecs'], 5)),
                (int) narh_reglage('cadence_max', 1800)
            );
            if ((int) $s['essai'] + $recul <= $maintenant) {
                $dues[] = $s;
            }
        }

        usort($dues, static fn (array $a, array $b): int => (int) $a['essai'] <=> (int) $b['essai']);

        return $dues;
    }

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        $st = $this->pdo->query('SELECT * FROM source ORDER BY rubrique, nom');

        return $st !== false ? $st->fetchAll() : [];
    }

    /** @param array<string, mixed> $champs */
    public function majSource(string $id, array $champs): void
    {
        if ($champs === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($champs)));
        $champs['id'] = $id;
        $this->pdo->prepare("UPDATE source SET $sets WHERE id = :id")->execute($champs);
    }

    /* ---- Articles -------------------------------------------------------- */

    /**
     * La dépêche est-elle déjà connue ? Renvoie son titre courant, ou null.
     *
     * Interrogé avant tout le reste : sur un cycle complet, les trois quarts
     * des entrées d'un flux ont déjà été vues. Les reconnaître tout de suite
     * évite d'analyser leur texte, de les rapprocher d'un événement — et
     * surtout d'ouvrir un groupe qui n'aurait jamais d'article.
     *
     * @return array{id: int, titre: string}|null
     */
    public function connu(string $cle): ?array
    {
        $st = $this->pdo->prepare('SELECT id, titre FROM article WHERE cle = ?');
        $st->execute([$cle]);
        $ligne = $st->fetch();

        return $ligne === false ? null : ['id' => (int) $ligne['id'], 'titre' => (string) $ligne['titre']];
    }

    /**
     * Le titre d'un direct évolue — « trois blessés » devient « quatre morts ».
     * On met le texte à jour sans toucher ni `vu` ni `date_tri` : la ligne ne
     * doit pas resurgir en tête du fil à chaque retouche de la rédaction.
     */
    public function rafraichir(int $id, string $titre, string $resume, int $maintenant): void
    {
        $this->pdo->prepare('UPDATE article SET titre = ?, resume = ?, maj = ? WHERE id = ?')
            ->execute([$titre, $resume, $maintenant, $id]);
    }

    /**
     * Insère une dépêche neuve.
     *
     * `OR IGNORE` reste une ceinture : le verrou de cycle empêche deux
     * collectes simultanées, mais la contrainte d'unicité doit rester ce qui
     * tranche en dernier ressort.
     *
     * @param array<string, mixed> $a
     * @return int|null l'identifiant si la dépêche est bien entrée, null sinon
     */
    public function inserer(array $a): ?int
    {
        $st = $this->pdo->prepare(
            'INSERT OR IGNORE INTO article
                (cle, source_id, groupe_id, titre, lien, resume, publie, vu, date_tri, maj,
                 lexique, score, niveau, motifs, jetons)
             VALUES
                (:cle, :source_id, :groupe_id, :titre, :lien, :resume, :publie, :vu, :date_tri, :maj,
                 :lexique, :score, :niveau, :motifs, :jetons)'
        );
        $st->execute($a);

        return $st->rowCount() > 0 ? (int) $this->pdo->lastInsertId() : null;
    }

    /**
     * Le filtre, en SQL. Écrit une fois pour les deux vues : le fil plat et
     * l'arbre doivent répondre au même filtre, sans quoi passer de l'un à
     * l'autre changerait ce qu'on regarde.
     *
     * Les alias attendus sont `a` (article), `s` (source), `g` (groupe).
     *
     * @param array<string, mixed> $f
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function clauses(array $f): array
    {
        $ou = ['1 = 1'];
        $args = [];

        if (!empty($f['depuis'])) {
            $ou[] = 'a.id > :depuis';
            $args['depuis'] = (int) $f['depuis'];
        }
        if (!empty($f['rubrique']) && $f['rubrique'] !== 'tout') {
            $ou[] = 's.rubrique = :rubrique';
            $args['rubrique'] = (string) $f['rubrique'];
        }
        if (!empty($f['source'])) {
            $ou[] = 'a.source_id = :source';
            $args['source'] = (string) $f['source'];
        }
        if (!empty($f['groupe'])) {
            $ou[] = 'a.groupe_id = :groupe';
            $args['groupe'] = (int) $f['groupe'];
        }
        if (!empty($f['niveau'])) {
            $ou[] = 'a.niveau >= :niveau';
            $args['niveau'] = (int) $f['niveau'];
        }
        if (!empty($f['q'])) {
            $ou[] = '(a.titre LIKE :q OR a.resume LIKE :q)';
            $args['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }

        /* Écarter, c'est sortir de la vue : sans filtre explicite, le fil ne
           montre plus ce qui a été mis de côté. `COALESCE` couvre les dépêches
           sans groupe, que le LEFT JOIN laisse à NULL. */
        $statut = (string) ($f['statut'] ?? '');
        if (in_array($statut, self::STATUTS, true) && $statut !== '') {
            $ou[] = 'g.statut = :statut';
            $args['statut'] = $statut;
        } else {
            $ou[] = "COALESCE(g.statut, '') <> 'ecarte'";
        }

        return [$ou, $args];
    }

    /**
     * Combien d'événements portent chaque marquage.
     *
     * Pour le Newsdesk : un onglet qui annonce « Suivis » sans dire combien
     * oblige à l'ouvrir pour savoir s'il vaut la peine d'être ouvert.
     *
     * @return array{suivi: int, traite: int, ecarte: int}
     */
    public function comptesStatuts(): array
    {
        $comptes = ['suivi' => 0, 'traite' => 0, 'ecarte' => 0];

        $st = $this->pdo->query(
            "SELECT statut, COUNT(*) AS n FROM groupe
             WHERE statut IN ('suivi', 'traite', 'ecarte') GROUP BY statut"
        );

        foreach ($st as $r) {
            $comptes[(string) $r['statut']] = (int) $r['n'];
        }

        return $comptes;
    }

    /**
     * La veille, cherchée par mots plutôt que par phrase.
     *
     * Pour l'agent (`Outils::rechercherActualites`), pas pour l'écran : le
     * modèle envoie « contribution des retraités aisés », et exiger la phrase
     * entière ratait l'article au pluriel près. Chaque mot vaut un point de
     * score, la meilleure correspondance partielle remonte au lieu d'un
     * silence — le même principe que la recherche d'otow-agent, réécrit contre
     * le schéma d'Ekein-Scrapper plutôt que lu depuis un fichier externe.
     *
     * @return list<array<string, mixed>>
     */
    public function chercherParMots(string $requete, int $limite = 5, string $rubrique = ''): array
    {
        $mots = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($requete))) ?: [],
            static fn (string $m): bool => mb_strlen($m) >= 3,
        ));
        if ($mots === []) {
            return [];
        }

        $conditions = [];
        $args = [];
        foreach ($mots as $i => $mot) {
            $conditions[] = "(CASE WHEN a.titre LIKE :m$i ESCAPE '\\' OR a.resume LIKE :m$i ESCAPE '\\' THEN 1 ELSE 0 END)";
            $args["m$i"] = '%' . str_replace(['%', '_'], ['\%', '\_'], $mot) . '%';
        }
        $score = implode(' + ', $conditions);

        /* Une rubrique restreint la recherche sans changer la façon de chercher :
           « ce qui se discute » et « ce qui est établi » sont la même requête sur
           un parc différent. Deux méthodes auraient divergé au premier réglage
           de pertinence touché d'un seul côté. */
        $filtre = '';
        if ($rubrique !== '' && $rubrique !== 'tout') {
            $filtre = ' AND s.rubrique = :rubrique';
            $args['rubrique'] = $rubrique;
        }

        /* Les colonnes rendues sont celles du fil, pas un sous-ensemble taillé
           pour le modèle : c'est ce qui permet aux sources d'une réponse de
           s'afficher avec la même ligne que la veille (`Piece::depeche`), et de
           renvoyer à leur dépêche d'origine. */
        $st = $this->pdo->prepare(
            "SELECT a.id, a.titre, a.resume, a.lien, a.date_tri, a.niveau, a.groupe_id,
                    s.nom AS source_nom, g.sources AS reprises, g.statut, ($score) AS pertinence
             FROM article a
             JOIN source s ON s.id = a.source_id
             LEFT JOIN groupe g ON g.id = a.groupe_id
             WHERE ($score) > 0" . $filtre . "
             ORDER BY pertinence DESC, a.date_tri DESC
             LIMIT " . max(1, min($limite, 20))
        );
        $st->execute($args);

        return $st->fetchAll();
    }

    /**
     * Le flux, filtré.
     *
     * @param array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    public function flux(array $f = []): array
    {
        [$ou, $args] = $this->clauses($f);

        /* Toutes les dépêches d'un même cycle portent le même `vu`. Les
           départager sur la date de publication remet chaque lot dans l'ordre
           de l'actualité : sans ce second critère, l'ordre d'insertion ferait
           passer la source relevée en dernier avant tout le reste — ce qui,
           sur la toute première collecte, met un quotidien régional en tête du
           fil pendant que la dépêche de l'heure est à la centième ligne. */
        $tri = ($f['tri'] ?? 'arrivee') === 'publication'
            ? 'a.date_tri DESC, a.id DESC'
            : 'a.vu DESC, a.date_tri DESC, a.id DESC';

        $limite = max(1, min((int) ($f['limite'] ?? 200), 1000));
        $sql = 'SELECT a.*, s.nom AS source_nom, s.rubrique, g.sources AS reprises,
                       g.fils, g.statut, g.titre AS groupe_titre
                FROM article a
                JOIN source s ON s.id = a.source_id
                LEFT JOIN groupe g ON g.id = a.groupe_id
                WHERE ' . implode(' AND ', $ou) . "
                ORDER BY $tri
                LIMIT $limite";

        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->fetchAll();
    }

    /**
     * Le fil en arbre : un événement, ses dépêches.
     *
     * Le fil plat répète — huit rédactions titrant le même fait font huit
     * lignes. Ici l'événement tient sur une, et porte ses reprises.
     *
     * **Un événement retenu montre toutes ses dépêches**, y compris celles que
     * le filtre ne retenait pas. C'est délibéré : sous « Monde », voir qu'une
     * régionale a titré dessus est précisément ce qu'on cherche. Le filtre dit
     * quels événements paraissent, pas ce qu'on en montre.
     *
     * @param array<string, mixed> $f mêmes clés que flux()
     * @return list<array<string, mixed>> chaque groupe porte ses `depeches`
     */
    public function arbre(array $f = [], int $evenements = 120): array
    {
        [$ou, $args] = $this->clauses($f);
        $evenements = max(1, min($evenements, 400));

        $st = $this->pdo->prepare(
            'SELECT g.*, MAX(a.date_tri) AS tri, MAX(a.id) AS dernier_article
             FROM article a
             JOIN source s ON s.id = a.source_id
             JOIN groupe g ON g.id = a.groupe_id
             WHERE ' . implode(' AND ', $ou) . "
             GROUP BY g.id
             ORDER BY tri DESC, g.id DESC
             LIMIT $evenements"
        );
        $st->execute($args);
        $groupes = $st->fetchAll();

        if ($groupes === []) {
            return [];
        }

        $ids = array_map(static fn (array $g): int => (int) $g['id'], $groupes);
        $trous = implode(',', array_fill(0, count($ids), '?'));

        $st = $this->pdo->prepare(
            "SELECT a.*, s.nom AS source_nom, s.rubrique, s.rang,
                    g.sources AS reprises, g.fils, g.statut
             FROM article a
             JOIN source s ON s.id = a.source_id
             LEFT JOIN groupe g ON g.id = a.groupe_id
             WHERE a.groupe_id IN ($trous)
             ORDER BY a.date_tri DESC, a.id DESC"
        );
        $st->execute($ids);

        $parGroupe = [];
        foreach ($st->fetchAll() as $a) {
            $parGroupe[(int) $a['groupe_id']][] = $a;
        }

        foreach ($groupes as &$g) {
            $g['depeches'] = $parGroupe[(int) $g['id']] ?? [];
        }

        return $groupes;
    }

    /**
     * Les événements qui ont reçu quelque chose depuis. `vu_dernier` est écrit
     * par recalculerGroupe() à chaque fois qu'un groupe est touché : c'est le
     * signal exact de « ce nœud a bougé, redemande-le ».
     *
     * @param array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    public function arbreDepuis(array $f, int $depuis, int $limite = 40): array
    {
        $groupes = $this->arbre($f, $limite * 3);

        return array_values(array_filter(
            $groupes,
            static fn (array $g): bool => (int) ($g['vu_dernier'] ?? 0) >= $depuis
        ));
    }

    /**
     * Les dépêches dont le niveau a bougé depuis un instant donné — une reprise
     * arrivée après coup fait monter des lignes déjà affichées. Sans cette
     * requête, l'écran garderait un « info » sur ce qui est devenu une alerte.
     *
     * @return list<array<string, mixed>>
     */
    public function promus(int $depuis, int $plafondId): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.niveau, a.score, g.sources AS reprises
             FROM article a
             LEFT JOIN groupe g ON g.id = a.groupe_id
             WHERE a.maj > :depuis AND a.id <= :plafond AND a.niveau > 0
             ORDER BY a.id DESC LIMIT 200'
        );
        $st->execute(['depuis' => $depuis, 'plafond' => $plafondId]);

        return $st->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function article(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT a.*, s.nom AS source_nom, s.rubrique, s.url AS source_url,
                    g.sources AS reprises, g.fils, g.titre AS groupe_titre, g.motifs AS groupe_motifs,
                    g.ia_niveau, g.ia_motif, g.statut
             FROM article a
             JOIN source s ON s.id = a.source_id
             LEFT JOIN groupe g ON g.id = a.groupe_id
             WHERE a.id = ?'
        );
        $st->execute([$id]);
        $ligne = $st->fetch();

        return $ligne === false ? null : $ligne;
    }

    /** Les autres dépêches du même événement. @return list<array<string, mixed>> */
    public function fratrie(int $groupeId, int $sauf): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.titre, a.lien, a.date_tri, s.nom AS source_nom
             FROM article a JOIN source s ON s.id = a.source_id
             WHERE a.groupe_id = ? AND a.id <> ? ORDER BY a.date_tri DESC LIMIT 12'
        );
        $st->execute([$groupeId, $sauf]);

        return $st->fetchAll();
    }

    /* ---- Groupes --------------------------------------------------------- */

    /**
     * Les groupes encore ouverts au rapprochement.
     *
     * @return list<array{id: int, titre: string, jetons: string, dernier: int}>
     */
    public function groupesActifs(int $depuis, int $limite = 900): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, titre, jetons, dernier FROM groupe
             WHERE dernier >= ? ORDER BY dernier DESC LIMIT ?'
        );
        $st->execute([$depuis, $limite]);

        return $st->fetchAll();
    }

    /** @param list<string> $jetons */
    public function creerGroupe(string $titre, array $jetons, int $quand, int $lexique, string $motifs): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO groupe (titre, jetons, premier, dernier, sources, articles, lexique, score, niveau, motifs)
             VALUES (:titre, :jetons, :quand, :quand, 1, 1, :lexique, :lexique, :niveau, :motifs)'
        );
        $st->execute([
            'titre'   => $titre,
            'jetons'  => implode(' ', $jetons),
            'quand'   => $quand,
            'lexique' => $lexique,
            'niveau'  => Alerte::niveau($lexique),
            'motifs'  => $motifs,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Recalcule un groupe à partir de ses dépêches, puis propage le niveau à
     * chacune d'elles.
     *
     * Le score du groupe est le meilleur score lexical de ses dépêches, plus la
     * reprise : une rédaction de plus qui titre dessus vaut un point, plafonné.
     * `reprise_max` est ce qui empêche un sujet marronnier repris par vingt
     * sites de passer devant un fait grave rapporté par trois.
     *
     * `sources` compte des **maisons**, pas des flux — voir le calcul plus bas.
     *
     * @return array{sources: int, fils: int, articles: int, score: int, niveau: int}
     */
    public function recalculerGroupe(int $id, int $maintenant): array
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) AS articles,
                    MAX(a.lexique) AS lexique,
                    MAX(a.date_tri) AS dernier,
                    MAX(a.vu) AS vu_dernier
             FROM article a WHERE a.groupe_id = ?'
        );
        $st->execute([$id]);
        $agg = $st->fetch() ?: [
            'articles' => 0, 'lexique' => 0, 'dernier' => $maintenant, 'vu_dernier' => $maintenant,
        ];

        // Une reprise ne se compte pas en articles, ni même en flux, mais en
        // **rédactions**. Le Monde publie cinq flux et BFM cinq : comptés un par
        // un, un événement qu'une seule maison porte vaudrait déjà cinq
        // confirmations. On regroupe donc par maison, en lui laissant le
        // meilleur poids de ses flux.
        //
        // Les agrégateurs sortent du calcul : Google Actualités recopie une
        // source déjà comptée. Le reprendre, ce serait compter deux fois la
        // même rédaction — et donner trois points à un sujet que personne n'a
        // confirmé.
        //
        // Le web social en sort pour une autre raison, plus grave : il ne
        // confirme pas, il commente. Un fil Reddit très suivi dit qu'un sujet
        // occupe les esprits, jamais qu'il est établi. Compté en reprise, une
        // rumeur virale prendrait le score d'un fait vérifié — exactement ce que
        // la reprise est censée distinguer.
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(x.poids), 0) AS poids,
                    COUNT(*) AS maisons,
                    COALESCE(SUM(x.continu), 0) AS fils
             FROM (
                 SELECT MAX(s.poids) AS poids,
                        MAX(CASE WHEN s.rang = 'continu' THEN 1 ELSE 0 END) AS continu
                 FROM article a JOIN source s ON s.id = a.source_id
                 WHERE a.groupe_id = ? AND s.rang NOT IN ('agregateur', 'social')
                 GROUP BY CASE WHEN s.maison = '' THEN s.id ELSE s.maison END
             ) x"
        );
        $st->execute([$id]);
        $conf = $st->fetch() ?: ['poids' => 0.0, 'maisons' => 0, 'fils' => 0];

        $maisons = (int) $conf['maisons'];
        $fils    = (int) $conf['fils'];

        $reprise = (int) min((float) narh_reglage('reprise_max', 5), max(0.0, (float) $conf['poids'] - 1.0));
        $score   = (int) $agg['lexique'] + $reprise;
        $niveau  = Alerte::niveau($score);

        $motifs = $reprise > 0 ? 'reprise ×' . $maisons : '';

        $this->pdo->prepare(
            'UPDATE groupe SET articles = :articles, sources = :sources, fils = :fils,
                    dernier = :dernier, vu_dernier = :vu_dernier,
                    lexique = :lexique, score = :score, niveau = :niveau,
                    motifs = CASE WHEN :motifs = \'\' THEN motifs ELSE :motifs END
             WHERE id = :id'
        )->execute([
            'articles'   => (int) $agg['articles'],
            'sources'    => $maisons,
            'fils'       => $fils,
            'dernier'    => (int) ($agg['dernier'] ?: $maintenant),
            // `dernier` est un MAX(date_tri) : une date annoncée par le flux,
            // qui peut reculer ou mentir. Pour savoir si un événement s'est tu,
            // il faut l'heure à laquelle *nous* avons vu passer quelque chose.
            'vu_dernier' => (int) ($agg['vu_dernier'] ?: $maintenant),
            'lexique'    => (int) $agg['lexique'],
            'score'      => $score,
            'niveau'     => $niveau,
            'motifs'     => $motifs,
            'id'         => $id,
        ]);

        // Seules les lignes qui changent portent un nouveau `maj` : c'est ce qui
        // permet au navigateur de ne recevoir que les promotions réelles.
        $this->pdo->prepare(
            'UPDATE article SET score = :score, niveau = :niveau, maj = :maj
             WHERE groupe_id = :id AND (niveau <> :niveau OR score <> :score)'
        )->execute([
            'score'  => $score,
            'niveau' => $niveau,
            'maj'    => $maintenant,
            'id'     => $id,
        ]);

        return [
            'sources'  => $maisons,
            'fils'     => $fils,
            'articles' => (int) $agg['articles'],
            'score'    => $score,
            'niveau'   => $niveau,
        ];
    }

    /**
     * Les événements en alerte, les plus récents d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function alertes(int $depuis, int $niveauMin = Alerte::ALERTE, int $limite = 12): array
    {
        /* Le lien de la dépêche de tête voyage avec l'événement : « ouvrir
           l'article » n'avait rien à ouvrir depuis les alertes, le Newsdesk ou
           la note de quart, et se rabattait sur un enfant `data-parent` qui
           n'existe que dans l'arbre. Il répondait donc « déplier l'événement »
           dans trois listes où rien ne se déplie. */
        $st = $this->pdo->prepare(
            'SELECT g.*, (
                 SELECT a.id FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_id, (
                 SELECT a.lien FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_lien
             FROM groupe g
             WHERE g.dernier >= :depuis AND g.niveau >= :niveau
             ORDER BY g.niveau DESC, g.dernier DESC
             LIMIT :limite'
        );
        $st->bindValue('depuis', $depuis, PDO::PARAM_INT);
        $st->bindValue('niveau', $niveauMin, PDO::PARAM_INT);
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /* ---- Conduite -------------------------------------------------------- */

    /** Les quatre états possibles d'un événement au desk. */
    public const STATUTS = ['', 'suivi', 'traite', 'ecarte'];

    /**
     * Marquer un événement.
     *
     * Le statut porte sur le groupe, pas sur la dépêche : on suit un sujet, et
     * les reprises qui arrivent ensuite en héritent sans qu'on ait à les
     * remarquer une à une.
     *
     * Renvoie false sur un statut inconnu — la valeur vient de l'adresse, elle
     * n'entre jamais telle quelle en base.
     */
    public function marquer(int $groupeId, string $statut, int $quand): bool
    {
        if (!in_array($statut, self::STATUTS, true)) {
            return false;
        }

        $st = $this->pdo->prepare(
            'UPDATE groupe SET statut = :statut, statut_maj = :quand WHERE id = :id'
        );
        $st->execute(['statut' => $statut, 'quand' => $quand, 'id' => $groupeId]);

        return $st->rowCount() > 0;
    }

    /**
     * La conduite : ce qui est retenu, le plus récent d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function conduite(int $limite = 20): array
    {
        $st = $this->pdo->prepare(
            "SELECT g.*, (
                 SELECT a.id FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_id
             FROM groupe g
             WHERE g.statut = 'suivi'
             ORDER BY g.dernier DESC
             LIMIT :limite"
        );
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * Les événements chauds qui se sont tus — à relancer.
     *
     * Le silence se mesure sur `vu_dernier`, l'heure à laquelle le collecteur a
     * vu passer la dernière dépêche du groupe. `dernier` ne conviendrait pas :
     * c'est une date annoncée par le flux, qui peut reculer ou mentir.
     *
     * La borne basse (`fenetre`) écarte ce qui est simplement vieux : un
     * événement d'avant-hier n'est pas « en train de retomber », il est clos.
     *
     * @return list<array<string, mixed>>
     */
    public function aRelancer(int $maintenant, int $minutes, int $niveauMin, int $limite = 8): array
    {
        $st = $this->pdo->prepare(
            "SELECT g.*, (
                 SELECT a.id FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_id
             FROM groupe g
             WHERE g.niveau >= :niveau
               AND g.statut <> 'ecarte'
               AND g.vu_dernier IS NOT NULL
               AND g.vu_dernier < :silence
               AND g.vu_dernier >= :plancher
             ORDER BY g.niveau DESC, g.vu_dernier DESC
             LIMIT :limite"
        );
        $st->bindValue('niveau', $niveauMin, PDO::PARAM_INT);
        $st->bindValue('silence', $maintenant - $minutes * 60, PDO::PARAM_INT);
        $st->bindValue('plancher', $maintenant - (int) narh_reglage('fenetre', 10800), PDO::PARAM_INT);
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * La prise de quart : ce qu'il faut savoir en arrivant.
     *
     * Quatre lots, parce qu'un desk pose quatre questions dans cet ordre :
     * qu'est-ce qui a éclaté, qu'est-ce qui a grossi, qu'est-ce qui tourne
     * encore, et qu'est-ce que personne n'a relancé.
     *
     * `evolue` exclut ce qui a éclaté pendant la période : un événement né
     * urgent y figurerait deux fois, sous « éclaté » puis sous « monté ».
     *
     * @return array{eclate: list<mixed>, evolue: list<mixed>, chaud: list<mixed>, relance: list<mixed>, compte: array<string, int>}
     */
    public function passation(int $depuis, int $maintenant): array
    {
        $lot = function (string $ou, array $args, string $ordre, int $limite = 12) : array {
            $st = $this->pdo->prepare(
                "SELECT g.*, (
                     SELECT a.id FROM article a WHERE a.groupe_id = g.id
                     ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
                 ) AS article_id
                 FROM groupe g
                 WHERE g.statut <> 'ecarte' AND $ou
                 ORDER BY $ordre
                 LIMIT $limite"
            );
            $st->execute($args);

            return $st->fetchAll();
        };

        $eclate = $lot(
            'g.premier >= :depuis AND g.niveau >= :niveau',
            ['depuis' => $depuis, 'niveau' => Alerte::ALERTE],
            'g.niveau DESC, g.premier DESC'
        );

        $evolue = $lot(
            'g.premier < :depuis AND g.vu_dernier >= :depuis AND g.niveau >= :niveau',
            ['depuis' => $depuis, 'niveau' => Alerte::ALERTE],
            'g.niveau DESC, g.vu_dernier DESC'
        );

        // « Chaud » se lit sur l'heure du relevé : un événement qui reçoit
        // encore, pas un qui affiche une date récente.
        $chaud = $lot(
            'g.vu_dernier >= :recent AND g.niveau >= :niveau',
            ['recent' => $maintenant - (int) narh_reglage('relance_minutes', 45) * 60, 'niveau' => Alerte::VEILLE],
            'g.sources DESC, g.vu_dernier DESC',
            8
        );

        $relance = $this->aRelancer(
            $maintenant,
            (int) narh_reglage('relance_minutes', 45),
            (int) narh_reglage('relance_niveau', Alerte::ALERTE)
        );

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM article WHERE vu >= ?');
        $st->execute([$depuis]);

        return [
            'eclate'  => $eclate,
            'evolue'  => $evolue,
            'chaud'   => $chaud,
            'relance' => $relance,
            'compte'  => [
                'depeches' => (int) $st->fetchColumn(),
                'eclate'   => count($eclate),
                'evolue'   => count($evolue),
                'relance'  => count($relance),
            ],
        ];
    }

    /* ---- Second avis ----------------------------------------------------- */

    /**
     * La clause « le score frôle un seuil, sans forcément l'avoir franchi » —
     * partagée par le second avis et par le signal faible du journal. Un seul
     * endroit : les deux doivent s'accorder sur ce qui compte comme
     * limitrophe, sinon l'un verrait un signal que l'autre ignore.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function bornesSeuils(int $marge): array
    {
        $seuils = [
            (int) narh_reglage('seuil_veille', 3),
            (int) narh_reglage('seuil_alerte', 7),
            (int) narh_reglage('seuil_urgent', 12),
        ];

        $bornes = [];
        $params = ['marge' => $marge];
        foreach ($seuils as $i => $seuil) {
            $bornes[] = "ABS(g.score - :seuil$i) <= :marge";
            $params["seuil$i"] = $seuil;
        }

        return [implode(' OR ', $bornes), $params];
    }

    /**
     * Les événements dont le score frôle un seuil, et que l'avis n'a pas
     * encore vus dans leur état courant.
     *
     * Au milieu d'un palier, le lexique tranche seul : demander un avis n'y
     * changerait rien. `ia_maj < dernier` suffit à ne rejuger qu'un événement
     * qui a bougé depuis — un second passage rapproché ne coûte alors rien.
     *
     * @return list<array<string, mixed>>
     */
    public function groupesACandidater(int $marge, int $lot): array
    {
        [$ou, $params] = $this->bornesSeuils($marge);
        $params['lot'] = $lot;

        $st = $this->pdo->prepare(
            "SELECT g.id, g.titre, g.score, g.niveau, g.sources, g.dernier, g.motifs,
                    g.ia_niveau, (
                 SELECT a.resume FROM article a WHERE a.groupe_id = g.id
                 ORDER BY LENGTH(a.resume) DESC LIMIT 1
             ) AS resume
             FROM groupe g
             WHERE ($ou)
               AND (g.ia_maj IS NULL OR g.ia_maj < g.dernier)
             ORDER BY g.dernier DESC
             LIMIT :lot"
        );
        foreach ($params as $cle => $valeur) {
            $st->bindValue($cle, $valeur, PDO::PARAM_INT);
        }
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * Ranger un avis. `ia_maj` est horodaté même quand l'avis est nul : un
     * modèle qui n'a rien su dire ne doit pas être redemandé au passage
     * suivant.
     */
    public function enregistrerAvisIa(int $groupeId, ?int $niveau, string $motif, int $quand): void
    {
        $st = $this->pdo->prepare(
            'UPDATE groupe SET ia_niveau = :niveau, ia_motif = :motif, ia_maj = :quand WHERE id = :id'
        );
        $st->execute([
            'niveau' => $niveau,
            'motif'  => $motif,
            'quand'  => $quand,
            'id'     => $groupeId,
        ]);
    }

    /* ---- Saillances -------------------------------------------------------
       Cinq choses qui arrivent dans la collecte, chacune à son instant, fondues
       en une liste triée plutôt qu'en listes séparées qui vieillissent chacune
       à sa façon.

       Ceci n'est **pas** la chronologie : la chronologie est `Journal`, en base,
       et il n'y en a qu'une (règle 7). Ce qui est calculé ici en est la matière
       — `Ecran::journaliserSaillances()` la lui verse au fil des cycles.

       La méthode s'est appelée `journal()` tant qu'elle venait d'Ekein-Scrapper,
       où elle alimentait un panneau du même nom. Deux choses portant le nom du
       concept central du projet, dont une morte, laissaient croire que la règle
       7 était branchée sur la collecte alors qu'elle ne l'était pas. */

    /**
     * Les saillances : grosse actu, signal faible, avis du second modèle, pic,
     * santé des flux — fusionnés et triés par instant décroissant.
     *
     * Chaque entrée porte des champs bruts, pas de texte déjà composé : comme
     * `alertes()` ou `conduite()`, c'est l'appelant qui tronque et met en mots.
     * Seul `categorie` dit quel gabarit appliquer à la ligne.
     *
     * @return list<array<string, mixed>>
     */
    public function saillances(int $depuis, int $limite = 40): array
    {
        /* Mesuré sur la base réelle : la marge du second avis, une fois
           partagée avec le desk, remonte des dizaines de signaux limitrophes
           par jour (souvent des scores sportifs « en direct », faux positifs
           du lexique) — largement de quoi noyer les deux ou trois grosses
           actus de la même fenêtre. Seule la grosse actu garde le plafond
           appelant : les autres catégories sont rationnées à part, pour que
           leur nombre ne décide jamais de la place qui reste à l'actu. */
        $quota = min($limite, 8);

        $entrees = [];

        // Grosse actu : l'événement a franchi le seuil d'alerte, quel que
        // soit son état antérieur — dernier(), pas premier(), pour capter
        // aussi une reprise tardive qui fait monter un vieux sujet.
        $st = $this->pdo->prepare(
            'SELECT g.*, (
                 SELECT a.id FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_id
             FROM groupe g
             WHERE g.niveau >= :alerte AND g.dernier >= :depuis
             ORDER BY g.dernier DESC LIMIT :limite'
        );
        $st->bindValue('alerte', Alerte::ALERTE, PDO::PARAM_INT);
        $st->bindValue('depuis', $depuis, PDO::PARAM_INT);
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll() as $g) {
            $entrees[] = ['quand' => (int) $g['dernier'], 'categorie' => 'actu', 'groupe' => $g];
        }

        // Signal faible : le score frôle un seuil sans l'avoir franchi — même
        // bornage que le second avis (bornesSeuils()), mais sans exiger que
        // l'avis n'ait pas encore été rendu : le desk veut voir le signal
        // même si Ollama l'a déjà regardé.
        [$ou, $params] = $this->bornesSeuils((int) narh_reglage('ia_marge', 2));
        $params['alerte'] = Alerte::ALERTE;
        $params['depuis'] = $depuis;
        $params['limite'] = $quota;
        $st = $this->pdo->prepare(
            "SELECT g.*, (
                 SELECT a.id FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_id
             FROM groupe g
             WHERE ($ou) AND g.niveau < :alerte AND g.dernier >= :depuis
             ORDER BY g.dernier DESC LIMIT :limite"
        );
        foreach ($params as $cle => $valeur) {
            $st->bindValue($cle, $valeur, PDO::PARAM_INT);
        }
        $st->execute();
        foreach ($st->fetchAll() as $g) {
            $entrees[] = ['quand' => (int) $g['dernier'], 'categorie' => 'signal', 'groupe' => $g];
        }

        // Avis du second modèle : tout jugement rendu dans la fenêtre, y
        // compris quand il confirme le barème — c'est le choix retenu, pas
        // seulement les désaccords.
        $st = $this->pdo->prepare(
            'SELECT g.*, (
                 SELECT a.id FROM article a WHERE a.groupe_id = g.id
                 ORDER BY a.niveau DESC, a.date_tri DESC LIMIT 1
             ) AS article_id
             FROM groupe g
             WHERE g.ia_maj IS NOT NULL AND g.ia_maj >= :depuis
             ORDER BY g.ia_maj DESC LIMIT :limite'
        );
        $st->bindValue('depuis', $depuis, PDO::PARAM_INT);
        $st->bindValue('limite', $quota, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll() as $g) {
            if ($g['ia_niveau'] === null) {
                continue; // avis nul : rien à montrer au desk
            }
            $entrees[] = ['quand' => (int) $g['ia_maj'], 'categorie' => 'ia', 'groupe' => $g];
        }

        // Le pic déjà commenté, s'il tombe dans la fenêtre choisie — une
        // fenêtre indépendante de celle qui sert à décider si le graphe
        // sous-jacent a encore un pic à montrer (commentairePic()) : ici on
        // ne fait que relire ce qui a été écrit, sans le requalifier.
        $brut = $this->meta('pic');
        if ($brut !== null) {
            $pic = json_decode($brut, true);
            $quand = is_array($pic) ? (int) ($pic['quand'] ?? 0) : 0;
            $texte = is_array($pic) ? trim((string) ($pic['texte'] ?? '')) : '';
            if ($quand >= $depuis && $texte !== '') {
                $entrees[] = ['quand' => $quand, 'categorie' => 'pic', 'texte' => $texte];
            }
        }

        // Santé des flux : la source vient de mourir ou de se rétablir, à
        // l'instant où Collecteur::transition() l'a constaté — pas une liste
        // permanente des 56.
        $morte = (int) narh_reglage('echecs_morte', 6);
        $st = $this->pdo->prepare(
            'SELECT nom, echecs, etat_change_le FROM source
             WHERE actif = 1 AND etat_change_le IS NOT NULL AND etat_change_le >= :depuis
             ORDER BY etat_change_le DESC LIMIT :limite'
        );
        $st->bindValue('depuis', $depuis, PDO::PARAM_INT);
        $st->bindValue('limite', $quota, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll() as $s) {
            $entrees[] = [
                'quand' => (int) $s['etat_change_le'],
                'categorie' => 'flux',
                'nom' => (string) $s['nom'],
                'morte' => (int) $s['echecs'] >= $morte,
            ];
        }

        usort($entrees, static fn (array $a, array $b): int => $b['quand'] <=> $a['quand']);

        return array_slice($entrees, 0, $limite);
    }

    /* ---- Compteurs ------------------------------------------------------- */

    /**
     * Le compte de dépêches par niveau sur une fenêtre arbitraire.
     *
     * `stats()` la fige à 6 h ; le panneau Journal doit pouvoir suivre la même
     * période que celle choisie pour le journal lui-même — d'où la fenêtre en
     * paramètre plutôt qu'en dur.
     *
     * @return list<int> indexé par Alerte::INFO..URGENT
     */
    public function niveaux(int $depuis): array
    {
        $niveaux = [0, 0, 0, 0];
        $st = $this->pdo->prepare(
            'SELECT niveau, COUNT(*) AS n FROM article WHERE vu >= ? GROUP BY niveau'
        );
        $st->execute([$depuis]);
        foreach ($st->fetchAll() as $l) {
            $niveaux[(int) $l['niveau']] = (int) $l['n'];
        }

        return $niveaux;
    }

    /** @return array<string, mixed> */
    public function stats(int $maintenant): array
    {
        $un = static fn (PDOStatement $st): int => (int) $st->fetchColumn();

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM article WHERE vu >= ?');
        $st->execute([$maintenant - 3600]);
        $h1 = $un($st);

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM article WHERE vu >= ?');
        $st->execute([$maintenant - 86400]);
        $h24 = $un($st);

        $niveaux = $this->niveaux($maintenant - 21600);

        $st = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN etat IN ('ok', 'inchange') THEN 1 ELSE 0 END) AS saines,
                SUM(CASE WHEN echecs > 0 AND echecs < :morte THEN 1 ELSE 0 END) AS fragiles,
                SUM(CASE WHEN echecs >= :morte THEN 1 ELSE 0 END) AS mortes
             FROM source WHERE actif = 1"
        );
        $st->execute(['morte' => (int) narh_reglage('echecs_morte', 6)]);
        $sources = $st->fetch() ?: [];

        return [
            'articles'  => (int) $this->pdo->query('SELECT COUNT(*) FROM article')->fetchColumn(),
            'h1'        => $h1,
            'h24'       => $h24,
            'niveaux'   => $niveaux,
            'sources'   => array_map('intval', $sources),
            'groupes'   => (int) $this->pdo->query('SELECT COUNT(*) FROM groupe')->fetchColumn(),
            'dernier_id' => (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM article')->fetchColumn(),
        ];
    }

    /**
     * Le débit : nombre de dépêches par tranche, sur une fenêtre. Sert de
     * données au xo-plot de l'écran.
     *
     * @return list<int>
     */
    public function debit(int $maintenant, int $minutes = 120, int $pas = 5): array
    {
        $tranches = (int) ceil($minutes / $pas);
        $depart = $maintenant - $tranches * $pas * 60;

        $st = $this->pdo->prepare(
            'SELECT CAST((vu - :depart) / :largeur AS INTEGER) AS tranche, COUNT(*) AS n
             FROM article WHERE vu >= :depart GROUP BY tranche'
        );
        $st->execute(['depart' => $depart, 'largeur' => $pas * 60]);

        $serie = array_fill(0, $tranches, 0);
        foreach ($st->fetchAll() as $l) {
            $i = (int) $l['tranche'];
            if ($i >= 0 && $i < $tranches) {
                $serie[$i] = (int) $l['n'];
            }
        }

        return $serie;
    }

    /**
     * Un pic : la dernière tranche pèse plus que la moyenne des précédentes,
     * d'un facteur, et dépasse un plancher.
     *
     * La tranche en cours est incomplète — elle se remplit encore. C'est
     * l'avant-dernière, close, qu'on compare : sinon un pic se signale une
     * tranche trop tôt, puis se dément.
     *
     * @param list<int> $serie
     */
    public static function picDetecte(array $serie, float $facteur, int $min): bool
    {
        if (count($serie) < 3) {
            return false;
        }

        $derniere = $serie[count($serie) - 2];
        $avant = array_slice($serie, 0, count($serie) - 2);
        $moyenne = array_sum($avant) / max(1, count($avant));

        return $derniere >= $min && $derniere >= $moyenne * $facteur;
    }

    /**
     * Les titres du dernier créneau, de quoi dire ce qui s'est passé.
     *
     * @return list<string>
     */
    public function titresRecents(int $depuis, int $limite = 15): array
    {
        $st = $this->pdo->prepare(
            'SELECT titre FROM article WHERE vu >= ? ORDER BY vu DESC LIMIT ?'
        );
        $st->bindValue(1, $depuis, PDO::PARAM_INT);
        $st->bindValue(2, $limite, PDO::PARAM_INT);
        $st->execute();

        return array_column($st->fetchAll(), 'titre');
    }

    /* ---- Meta et entretien ----------------------------------------------- */

    public function meta(string $cle, ?string $defaut = null): ?string
    {
        $st = $this->pdo->prepare('SELECT valeur FROM meta WHERE cle = ?');
        $st->execute([$cle]);
        $v = $st->fetchColumn();

        return $v === false ? $defaut : (string) $v;
    }

    /**
     * Le dernier commentaire de pic, s'il vaut encore pour le graphe affiché.
     *
     * Passé la fenêtre du graphe, le pic commenté n'y figure plus : garder le
     * texte donnerait une explication à une courbe plate.
     */
    public function commentairePic(int $maintenant, int $duree): ?string
    {
        $brut = $this->meta('pic');
        if ($brut === null) {
            return null;
        }

        $pic = json_decode($brut, true);
        if (!is_array($pic) || ($maintenant - (int) ($pic['quand'] ?? 0)) > $duree) {
            return null;
        }
        $texte = trim((string) ($pic['texte'] ?? ''));

        return $texte === '' ? null : $texte;
    }

    public function setMeta(string $cle, string $valeur): void
    {
        $this->pdo->prepare(
            'INSERT INTO meta (cle, valeur) VALUES (?, ?)
             ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur'
        )->execute([$cle, $valeur]);
    }

    /** @return array<string, mixed> */
    public function cycle(): array
    {
        $json = $this->meta('cycle');
        $data = $json !== null ? json_decode($json, true) : null;

        return is_array($data) ? $data : ['fin' => 0, 'ms' => 0, 'sources' => 0, 'nouveaux' => 0, 'erreurs' => 0];
    }

    /**
     * Supprime ce qui est trop vieux. Retourne le nombre de dépêches effacées.
     *
     * Un événement marqué au desk échappe à la rétention, ses dépêches avec :
     * une conduite qui se viderait toute seule au bout de quatre jours ne
     * serait pas une conduite. Un événement écarté, lui, n'a aucune raison de
     * survivre — c'est même le seul dont on veut se débarrasser.
     */
    public function purger(int $avant): int
    {
        $st = $this->pdo->prepare(
            "DELETE FROM article WHERE vu < ? AND groupe_id NOT IN (
                 SELECT id FROM groupe WHERE statut IN ('suivi', 'traite')
             )"
        );
        $st->execute([$avant]);
        $n = $st->rowCount();

        $this->pdo->prepare(
            "DELETE FROM groupe
             WHERE statut NOT IN ('suivi', 'traite')
               AND id NOT IN (SELECT DISTINCT groupe_id FROM article WHERE groupe_id IS NOT NULL)"
        )->execute();

        return $n;
    }

    public function compacter(): void
    {
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->pdo->exec('VACUUM');
    }
}
