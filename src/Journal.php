<?php
declare(strict_types=1);

/**
 * La chronologie — une seule, pour la collecte comme pour l'agent (règle 7).
 *
 * C'est le seul endroit où l'on verra « alerte à 04:30 → conduite déclenchée →
 * 1 200 jetons → note ». Deux journaux séparés — un fichier pour l'agent, une
 * table pour la veille, comme dans les deux projets d'origine — rendraient le
 * méta-agent aveugle à lui-même : personne ne saurait dire ce qui a suivi quoi.
 *
 * Elle vit dans `narh.sqlite`, pas dans la base de collecte : la collecte est
 * reconstructible en un cycle, le journal ne l'est pas.
 *
 * Deux écrivains possibles en même temps — le démon et l'écran — d'où le WAL et
 * le `busy_timeout` : sans eux, un cycle en cours ferait échouer l'écriture de
 * l'écran plutôt que de la faire attendre.
 */
final class Journal
{
    /** Au-delà, les plus vieilles lignes sortent. Un journal sans plafond finit
        par peser plus que la base qu'il commente. */
    private const GARDE = 5000;

    /**
     * Noter un fait.
     *
     * @param string $niveau  ok | info | warn | error — l'issue
     * @param string $source  collecte | agent | écran — qui parle
     * @param ?int   $duree   millisecondes, quand la chose a duré
     */
    public static function noter(string $niveau, string $source, string $message, ?int $duree = null): void
    {
        /* Le journal se relit ligne à ligne. Un message qui contient un saut de
           ligne — le contenu d'un fichier lu par un outil, l'erreur d'un flux —
           coupait l'entrée en deux dans la version fichier du projet d'origine :
           27 lignes bancales sur 460. Ici la base l'encaisserait, mais l'écran
           afficherait quand même une ligne haute de dix rangées. */
        $plat = trim(strtr($message, ["\r\n" => ' ⏎ ', "\n" => ' ⏎ ', "\r" => ' ⏎ ', "\t" => ' ']));

        try {
            $st = self::pdo()->prepare(
                'INSERT INTO journal (quand, niveau, source, message, duree) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([time(), $niveau, $source, mb_substr($plat, 0, 400), $duree]);
        } catch (Throwable) {
            /* Un journal qui empêche d'agir est pire que pas de journal. Une
               écriture perdue coûte une ligne ; une exception ici couperait un
               cycle de collecte au milieu. */
        }
    }

    /**
     * Les dernières lignes, de la plus récente à la plus ancienne.
     *
     * @return list<array{quand: int, niveau: string, source: string, message: string, duree: ?int}>
     */
    public static function lire(int $limite = 60): array
    {
        try {
            $st = self::pdo()->prepare(
                'SELECT quand, niveau, source, message, duree FROM journal ORDER BY id DESC LIMIT ?'
            );
            $st->execute([$limite]);

            return $st->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** Ce qui s'est passé depuis un instant — le compteur de la barre d'état. */
    public static function compter(int $depuis): int
    {
        try {
            $st = self::pdo()->prepare('SELECT COUNT(*) FROM journal WHERE quand >= ?');
            $st->execute([$depuis]);

            return (int) $st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Rogner la queue. Appelé en fin de cycle, pas à chaque ligne. */
    public static function rogner(): void
    {
        try {
            self::pdo()->exec(
                'DELETE FROM journal WHERE id <= (SELECT MAX(id) FROM journal) - ' . self::GARDE
            );
        } catch (Throwable) {
        }
    }

    private static bool $prete = false;

    private static function pdo(): PDO
    {
        $pdo = Db::narh();

        if (!self::$prete) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS journal (
                    id      INTEGER PRIMARY KEY AUTOINCREMENT,
                    quand   INTEGER NOT NULL,
                    niveau  TEXT    NOT NULL,
                    source  TEXT    NOT NULL,
                    message TEXT    NOT NULL,
                    duree   INTEGER
                );
                CREATE INDEX IF NOT EXISTS idx_journal_quand ON journal(quand DESC);
                SQL);
            self::$prete = true;
        }

        return $pdo;
    }
}
