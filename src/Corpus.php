<?php
declare(strict_types=1);

/**
 * Le corpus plein texte — ce que NARH a vraiment lu.
 *
 * La veille connaît des titres et des résumés ; le corpus connaît le texte.
 * C'est la différence entre « le modèle sait qu'un article existe » et « le
 * modèle peut le citer ».
 *
 * **Le grain est le paragraphe, pas l'article** (CLAUDE.md, règles du projet) :
 * c'est ce qui permet de donner au modèle le passage utile plutôt que trois
 * pages. FTS5 plutôt qu'un `LIKE` : il apporte le classement par pertinence
 * (bm25) et les opérateurs de proximité qu'un comptage de mots ne sait pas
 * rendre.
 *
 * Il vit dans `narh.sqlite`, avec le journal et les fils — c'est de la mémoire,
 * pas de la collecte (règle 3). Chez otow-agent il y vivait pour une autre
 * raison : la base de la veille appartenait à un projet voisin qu'on ouvrait en
 * lecture seule. Cette raison est morte avec la fusion ; la place, elle, était
 * la bonne.
 *
 * `lien_vu` remplace le `data/liens.json` d'otow : un cache en JSON à côté
 * d'une base fait partie de ce qu'on ne recopie pas.
 */
final class Corpus
{
    private static bool $prete = false;

    private static function pdo(): PDO
    {
        $pdo = Db::narh();

        if (!self::$prete) {
            $pdo->exec(<<<'SQL'
                CREATE VIRTUAL TABLE IF NOT EXISTS passage USING fts5(
                    texte,
                    lien   UNINDEXED,
                    titre  UNINDEXED,
                    source UNINDEXED,
                    date   UNINDEXED,
                    rang   UNINDEXED
                );

                /* Ce qui a déjà été lu, succès comme échec : sans cette trace,
                   chaque passage ré-essaierait les murs payants et les pages
                   mortes. */
                CREATE TABLE IF NOT EXISTS article_lu (
                    lien     TEXT PRIMARY KEY,
                    quand    INTEGER NOT NULL,
                    passages INTEGER NOT NULL DEFAULT 0,
                    statut   TEXT    NOT NULL DEFAULT 'ok'
                );

                /* Les liens dont on sait s'ils répondent. Vérifier pendant une
                   réponse coûterait une demi-seconde par source au moment le
                   plus sensible — l'écran affiche d'abord, estompe ensuite. */
                CREATE TABLE IF NOT EXISTS lien_vu (
                    lien  TEXT PRIMARY KEY,
                    ok    INTEGER NOT NULL,
                    quand INTEGER NOT NULL
                );
                SQL);
            self::$prete = true;
        }

        return $pdo;
    }

    /* ---- Recherche --------------------------------------------------------- */

    /**
     * Une requête d'utilisateur traduite en expression FTS5.
     *
     * Les mots courts sortent : sous quatre lettres ce sont des articles et des
     * prépositions, qui ramènent tout le corpus et ne classent rien. `OR` et non
     * `AND` : une question posée en langue naturelle contient rarement les mots
     * exacts de l'article, et bm25 fait le tri du reste.
     */
    public static function requeteFts(string $requete): string
    {
        $mots = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($requete))) ?: [];
        $mots = array_values(array_unique(array_filter(
            $mots,
            static fn (string $m): bool => mb_strlen($m) >= 4
        )));

        if ($mots === []) {
            return '';
        }

        return implode(' OR ', array_map(static fn (string $m): string => '"' . $m . '"', $mots));
    }

    /**
     * Les passages les plus pertinents, un par article.
     *
     * @return list<array<string, mixed>>
     */
    public static function chercher(string $requete, int $limite = 5): array
    {
        $termes = self::requeteFts($requete);
        if ($termes === '') {
            return [];
        }

        $st = self::pdo()->prepare(
            'SELECT texte, lien, titre, source, date, bm25(passage) AS score
             FROM passage
             WHERE passage MATCH :q
             ORDER BY score
             LIMIT :limite'
        );
        $st->bindValue('q', $termes, PDO::PARAM_STR);
        // On demande large puis on dédoublonne par article, plus simple qu'un
        // GROUP BY sur une table FTS5.
        $st->bindValue('limite', max(1, $limite) * 6, PDO::PARAM_INT);
        $st->execute();

        $vus = [];
        $out = [];
        foreach ($st->fetchAll() as $p) {
            if (isset($vus[$p['lien']])) {
                continue;
            }
            $vus[$p['lien']] = true;
            $out[] = $p;

            if (count($out) >= $limite) {
                break;
            }
        }

        return $out;
    }

    /* ---- Ingestion --------------------------------------------------------- */

    public static function dejaLu(string $lien): bool
    {
        $st = self::pdo()->prepare('SELECT 1 FROM article_lu WHERE lien = ?');
        $st->execute([$lien]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Range les paragraphes d'un article dans le corpus.
     *
     * @param list<string> $paragraphes
     */
    public static function ingerer(string $lien, string $titre, string $source, string $date, array $paragraphes): int
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            // On efface avant d'écrire : une réingestion ne doit pas doubler les
            // passages d'un article déjà lu.
            $pdo->prepare('DELETE FROM passage WHERE lien = ?')->execute([$lien]);

            $ins = $pdo->prepare(
                'INSERT INTO passage (texte, lien, titre, source, date, rang) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $rang = 0;
            foreach ($paragraphes as $texte) {
                $ins->execute([$texte, $lien, $titre, $source, $date, $rang++]);
            }

            $pdo->prepare(
                'INSERT INTO article_lu (lien, quand, passages, statut) VALUES (:l, :q, :n, :s)
                 ON CONFLICT(lien) DO UPDATE SET quand = :q, passages = :n, statut = :s'
            )->execute([
                'l' => $lien,
                'q' => time(),
                'n' => count($paragraphes),
                's' => $paragraphes === [] ? 'vide' : 'ok',
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return count($paragraphes);
    }

    /**
     * Marque un article comme illisible : mur payant, page morte, hôte
     * injoignable. Sans cette trace, chaque passage le ré-essaierait.
     */
    public static function marquerIllisible(string $lien, string $statut = 'illisible'): void
    {
        self::pdo()->prepare(
            'INSERT INTO article_lu (lien, quand, passages, statut) VALUES (:l, :q, 0, :s)
             ON CONFLICT(lien) DO UPDATE SET quand = :q, statut = :s'
        )->execute(['l' => $lien, 'q' => time(), 's' => $statut]);
    }

    /* ---- Liens ------------------------------------------------------------- */

    /**
     * Le verdict connu sur un lien, si la vérification n'est pas trop vieille.
     *
     * Une semaine : au-delà, une page qui répondait a pu disparaître, et une
     * qui ne répondait pas a pu revenir.
     */
    public static function lienConnu(string $lien, int $peremption = 604800): ?bool
    {
        $st = self::pdo()->prepare('SELECT ok, quand FROM lien_vu WHERE lien = ?');
        $st->execute([$lien]);
        $l = $st->fetch();

        if ($l === false || (time() - (int) $l['quand']) > $peremption) {
            return null;
        }

        return (bool) $l['ok'];
    }

    public static function noterLien(string $lien, bool $ok): void
    {
        self::pdo()->prepare(
            'INSERT INTO lien_vu (lien, ok, quand) VALUES (:l, :o, :q)
             ON CONFLICT(lien) DO UPDATE SET ok = :o, quand = :q'
        )->execute(['l' => $lien, 'o' => $ok ? 1 : 0, 'q' => time()]);
    }

    /* ---- Compteurs --------------------------------------------------------- */

    /** @return array{articles: int, passages: int, echecs: int, liens: int} */
    public static function etat(): array
    {
        $pdo = self::pdo();

        return [
            'articles' => (int) $pdo->query("SELECT COUNT(*) FROM article_lu WHERE statut = 'ok'")->fetchColumn(),
            'passages' => (int) $pdo->query('SELECT COUNT(*) FROM passage')->fetchColumn(),
            'echecs'   => (int) $pdo->query("SELECT COUNT(*) FROM article_lu WHERE statut <> 'ok'")->fetchColumn(),
            'liens'    => (int) $pdo->query('SELECT COUNT(*) FROM lien_vu')->fetchColumn(),
        ];
    }

    /**
     * Par maison, ce que le corpus contient.
     *
     * @return list<array<string, mixed>>
     */
    public static function parSource(int $limite = 12): array
    {
        $st = self::pdo()->prepare(
            'SELECT source, COUNT(DISTINCT lien) AS articles, COUNT(*) AS passages
             FROM passage GROUP BY source ORDER BY passages DESC LIMIT :limite'
        );
        $st->bindValue('limite', $limite, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * Repasse le filtre d'habillage sur l'existant.
     *
     * Un motif ajouté après coup ne nettoie pas ce qui est déjà rangé : sans
     * cette passe, le corpus garderait pour toujours les encarts d'abonnement
     * ingérés avant que leur motif soit écrit.
     *
     * @return array{avant: int, apres: int}
     */
    public static function nettoyer(): array
    {
        $pdo = self::pdo();
        $avant = (int) $pdo->query('SELECT COUNT(*) FROM passage')->fetchColumn();

        $aRetirer = [];
        foreach ($pdo->query('SELECT rowid, texte FROM passage')->fetchAll() as $p) {
            if (Lecture::estHabillage((string) $p['texte'])) {
                $aRetirer[] = (int) $p['rowid'];
            }
        }

        foreach (array_chunk($aRetirer, 200) as $lot) {
            $trous = implode(',', array_fill(0, count($lot), '?'));
            $pdo->prepare("DELETE FROM passage WHERE rowid IN ($trous)")->execute($lot);
        }

        return ['avant' => $avant, 'apres' => (int) $pdo->query('SELECT COUNT(*) FROM passage')->fetchColumn()];
    }
}
