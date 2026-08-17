<?php
declare(strict_types=1);

/**
 * Les fils de conversation — la mémoire de l'agent, en base.
 *
 * Portée depuis otow-agent, où la conversation vivait un temps en `$_SESSION` :
 * fermer l'onglet effaçait tout, bilans de méta-cognition compris, alors qu'ils
 * ont coûté des jetons à produire. Ici tout vit dans `narh.sqlite` dès le
 * premier message ; la session ne garde qu'un pointeur (CLAUDE.md, § Ce qu'on
 * ne recopie pas).
 *
 * Deux tables : `fil` (un fil, son titre, ses horodatages) et `message` (un
 * tour, avec ses étapes d'outils et son bilan en JSON).
 */
final class Memoire
{
    private static bool $prete = false;

    private static function pdo(): PDO
    {
        $pdo = Db::narh();

        if (!self::$prete) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS fil (
                    id    INTEGER PRIMARY KEY AUTOINCREMENT,
                    titre TEXT    NOT NULL DEFAULT '',
                    debut INTEGER NOT NULL,
                    maj   INTEGER NOT NULL
                );

                CREATE TABLE IF NOT EXISTS message (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    fil_id   INTEGER NOT NULL REFERENCES fil(id) ON DELETE CASCADE,
                    role     TEXT    NOT NULL,
                    contenu  TEXT    NOT NULL,
                    quand    INTEGER NOT NULL,
                    heure    TEXT    NOT NULL,
                    etapes   TEXT    NOT NULL DEFAULT '[]',
                    bilan    TEXT,
                    jetons   INTEGER NOT NULL DEFAULT 0,
                    consomme INTEGER NOT NULL DEFAULT 0
                );

                CREATE INDEX IF NOT EXISTS idx_message_fil ON message(fil_id, id);
                CREATE INDEX IF NOT EXISTS idx_fil_maj ON fil(maj DESC);
                SQL);

            /* Les tuiles sont arrivées après les premiers fils : la colonne
               s'ajoute si elle manque. `ALTER TABLE` échoue quand elle est déjà
               là — SQLite n'a pas d'`IF NOT EXISTS` pour les colonnes — et cet
               échec-là est le cas normal, pas un incident. */
            try {
                $pdo->exec("ALTER TABLE message ADD COLUMN tuiles TEXT NOT NULL DEFAULT '[]'");
            } catch (PDOException) {
            }

            /* Les jetons **relus** — la taille du contexte envoyé au modèle,
               distincte des jetons produits. C'est ce qui remplit la fenêtre, et
               le seul chiffre qui dise quand un fil va la saturer. */
            try {
                $pdo->exec('ALTER TABLE message ADD COLUMN contexte INTEGER NOT NULL DEFAULT 0');
            } catch (PDOException) {
            }

            self::$prete = true;
        }

        return $pdo;
    }

    public static function filCreer(): int
    {
        $maintenant = time();
        $st = self::pdo()->prepare('INSERT INTO fil (titre, debut, maj) VALUES (\'\', ?, ?)');
        $st->execute([$maintenant, $maintenant]);

        return (int) self::pdo()->lastInsertId();
    }

    public static function filExiste(int $id): bool
    {
        $st = self::pdo()->prepare('SELECT 1 FROM fil WHERE id = ?');
        $st->execute([$id]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Les fils récents, le plus fraîchement touché en tête.
     *
     * Un fil vide n'a rien à montrer et encombrerait la liste — sauf celui
     * qu'on vient d'ouvrir, qui doit rester visible le temps d'y écrire.
     *
     * @return list<array{id: int, titre: string, maj: int, tours: int, dernier: ?string}>
     */
    public static function fils(int $limite = 30, int $courant = 0): array
    {
        $st = self::pdo()->prepare(
            'SELECT f.id, f.titre, f.maj, COUNT(m.id) AS tours,
                    (SELECT contenu FROM message WHERE fil_id = f.id ORDER BY id DESC LIMIT 1) AS dernier
             FROM fil f
             LEFT JOIN message m ON m.fil_id = f.id
             GROUP BY f.id
             HAVING tours > 0 OR f.id = :courant
             ORDER BY f.maj DESC
             LIMIT :limite'
        );
        $st->bindValue('courant', $courant, PDO::PARAM_INT);
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * Efface les fils restés vides.
     *
     * Chaque « fil neuf » ouvre un fil, même si l'on n'y écrit jamais : sans ce
     * ménage, la liste se remplirait de coquilles. Le fil courant est épargné —
     * il est vide par définition à la seconde où on l'ouvre.
     */
    public static function purgerVides(int $sauf = 0): int
    {
        $st = self::pdo()->prepare(
            'DELETE FROM fil WHERE id <> :sauf AND id NOT IN (SELECT DISTINCT fil_id FROM message)'
        );
        $st->execute(['sauf' => $sauf]);

        return $st->rowCount();
    }

    /**
     * Le titre vient de la première question posée : c'est ce qui permet de
     * reconnaître un fil sans l'ouvrir. Écrit une seule fois — un titre qui
     * suivrait le dernier message changerait sous les yeux.
     */
    public static function filTitrer(int $id): void
    {
        $st = self::pdo()->prepare('SELECT titre FROM fil WHERE id = ?');
        $st->execute([$id]);
        if (trim((string) $st->fetchColumn()) !== '') {
            return;
        }

        $st = self::pdo()->prepare(
            "SELECT contenu FROM message WHERE fil_id = ? AND role = 'user' ORDER BY id LIMIT 1"
        );
        $st->execute([$id]);
        $premier = trim((string) $st->fetchColumn());
        if ($premier === '') {
            return;
        }

        self::pdo()->prepare('UPDATE fil SET titre = ? WHERE id = ?')
            ->execute([mb_strimwidth($premier, 0, 60, '…'), $id]);
    }

    public static function filSupprimer(int $id): void
    {
        // ON DELETE CASCADE emporte les messages : PRAGMA foreign_keys est posé
        // à l'ouverture (Db::narh()) — sans lui SQLite l'ignorerait en silence.
        self::pdo()->prepare('DELETE FROM fil WHERE id = ?')->execute([$id]);
    }

    /**
     * @param list<array<string, mixed>> $etapes
     * @param list<Tuile>                $tuiles
     */
    public static function messageAjouter(
        int $filId,
        string $role,
        string $contenu,
        array $etapes = [],
        ?array $bilan = null,
        int $jetons = 0,
        array $tuiles = [],
        int $contexte = 0,
    ): void {
        $st = self::pdo()->prepare(
            'INSERT INTO message (fil_id, role, contenu, quand, heure, etapes, bilan, jetons, tuiles, contexte)
             VALUES (:f, :role, :contenu, :quand, :heure, :etapes, :bilan, :jetons, :tuiles, :contexte)'
        );
        $st->execute([
            'f'       => $filId,
            'role'    => $role,
            'contenu' => $contenu,
            'quand'   => time(),
            'heure'   => date('H:i:s'),
            'etapes'  => json_encode($etapes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'bilan'   => $bilan === null ? null : json_encode($bilan, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'jetons'  => $jetons,
            // On ne garde que le descripteur : une tuile se refait à
            // l'affichage, elle ne se photographie pas.
            'tuiles'  => json_encode(
                array_map(static fn (Tuile $t): array => $t->enTableau(), $tuiles),
                JSON_UNESCAPED_UNICODE,
            ),
            'contexte' => $contexte,
        ]);

        self::pdo()->prepare('UPDATE fil SET maj = ? WHERE id = ?')->execute([time(), $filId]);
        self::filTitrer($filId);
    }

    /**
     * Les tours d'un fil, dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(int $filId, int $limite = 200): array
    {
        // On prend les N *derniers* tours, donc en ordre décroissant, puis on
        // remet le fil à l'endroit en PHP : trier à nouveau en SQL demanderait
        // une sous-requête dont les colonnes de tri auraient disparu.
        $st = self::pdo()->prepare(
            'SELECT role, contenu, quand, heure, etapes, bilan, jetons, consomme, tuiles
             FROM message WHERE fil_id = :f
             ORDER BY id DESC LIMIT :limite'
        );
        $st->bindValue('f', $filId, PDO::PARAM_INT);
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();

        $tours = [];
        foreach (array_reverse($st->fetchAll()) as $m) {
            $tuiles = [];
            foreach (json_decode((string) ($m['tuiles'] ?? '[]'), true) ?: [] as $brut) {
                $tuile = is_array($brut) ? Tuile::depuisTableau($brut) : null;
                if ($tuile !== null) {
                    $tuiles[] = $tuile;
                }
            }

            $tours[] = [
                'role'     => (string) $m['role'],
                'content'  => (string) $m['contenu'],
                // L'instant, pas seulement l'heure lisible : le flux mêle des
                // tours et des segments d'antenne, et il faut pouvoir les
                // ordonner entre eux.
                'quand'    => (int) $m['quand'],
                'heure'    => (string) $m['heure'],
                'etapes'   => json_decode((string) $m['etapes'], true) ?: [],
                'bilan'    => $m['bilan'] !== null ? json_decode((string) $m['bilan'], true) : null,
                'jetons'   => (int) $m['jetons'],
                'consomme' => (bool) $m['consomme'],
                'tuiles'   => $tuiles,
            ];
        }

        return $tours;
    }

    public static function fermerOutils(int $filId): int
    {
        $st = self::pdo()->prepare(
            "UPDATE message SET consomme = 1 WHERE fil_id = ? AND role = 'outil' AND consomme = 0"
        );
        $st->execute([$filId]);

        return $st->rowCount();
    }

    /** Le coût d'un fil, en jetons. */
    public static function cout(int $filId): int
    {
        $st = self::pdo()->prepare('SELECT COALESCE(SUM(jetons), 0) FROM message WHERE fil_id = ?');
        $st->execute([$filId]);

        return (int) $st->fetchColumn();
    }

    /**
     * Les appels d'outils du fil, du plus récent au plus ancien.
     *
     * Ils sont déjà stockés — dans les `etapes` de chaque tour — et restent
     * affichés dans la conversation. Les rassembler ici en donne une seconde
     * lecture : non plus « ce que cette réponse a consulté », mais « ce que
     * l'agent a fait depuis le début », qu'on peut rejouer ou inspecter.
     *
     * @return list<array{outil: string, arguments: array, ok: bool, resultat: mixed, quand: int, heure: string}>
     */
    /**
     * Ce que le compteur « Outils » doit dire, sans rendre la liste.
     *
     * `outils()` décode soixante messages pour bâtir un poste de commande ;
     * le compteur n'a besoin que de deux nombres, et il se rafraîchit bien plus
     * souvent que le panneau. Les séparer évite de payer le rendu complet à
     * chaque fois qu'on veut seulement savoir s'il s'est passé quelque chose.
     *
     * Les échecs comptent à part : un outil qui a échoué ne se voit pas dans un
     * total, et c'est pourtant la seule chose du panneau qui demande qu'on aille
     * y regarder.
     *
     * @return array{compte: int, echecs: int}
     */
    public static function etatOutils(int $filId): array
    {
        $appels = self::outils($filId, 60);
        $echecs = 0;
        foreach ($appels as $a) {
            if (!$a['ok']) {
                $echecs++;
            }
        }

        return ['compte' => count($appels), 'echecs' => $echecs];
    }

    public static function outils(int $filId, int $limite = 20): array
    {
        $st = self::pdo()->prepare(
            "SELECT quand, heure, etapes FROM message
             WHERE fil_id = ? AND etapes <> '[]'
             ORDER BY id DESC LIMIT 60"
        );
        $st->execute([$filId]);

        $appels = [];
        foreach ($st->fetchAll() as $m) {
            foreach (json_decode((string) $m['etapes'], true) ?: [] as $etape) {
                if (!is_array($etape) || ($etape['outil'] ?? '') === '') {
                    continue;
                }

                $appels[] = [
                    'outil'     => (string) $etape['outil'],
                    'arguments' => is_array($etape['arguments'] ?? null) ? $etape['arguments'] : [],
                    'ok'        => (bool) ($etape['ok'] ?? true),
                    'resultat'  => $etape['resultat'] ?? null,
                    'quand'     => (int) $m['quand'],
                    'heure'     => (string) $m['heure'],
                ];

                if (count($appels) >= $limite) {
                    return $appels;
                }
            }
        }

        return $appels;
    }

    /**
     * Les jetons relus au dernier tour d'un fil — ce qui occupe la fenêtre.
     *
     * Le dernier, pas la somme : le contexte n'est pas cumulatif, il est
     * reconstruit à chaque tour. Additionner les tours donnerait un chiffre
     * énorme et faux.
     */
    public static function contexteDernier(int $filId): int
    {
        $st = self::pdo()->prepare(
            'SELECT contexte FROM message WHERE fil_id = ? AND contexte > 0 ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$filId]);

        return (int) $st->fetchColumn();
    }

    /**
     * Ce que l'utilisateur a consommé, tous fils confondus.
     *
     * @return array{fils: int, tours: int, jetons: int}
     */
    public static function bilan(): array
    {
        $r = self::pdo()->query(
            'SELECT (SELECT COUNT(*) FROM fil) AS fils,
                    (SELECT COUNT(*) FROM message) AS tours,
                    (SELECT COALESCE(SUM(jetons), 0) FROM message) AS jetons'
        )->fetch();

        return [
            'fils'   => (int) ($r['fils'] ?? 0),
            'tours'  => (int) ($r['tours'] ?? 0),
            'jetons' => (int) ($r['jetons'] ?? 0),
        ];
    }
}
