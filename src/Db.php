<?php
declare(strict_types=1);

/**
 * La connexion à `narh.sqlite` — la mémoire de NARH : fils, messages, journal,
 * corpus. Un seul point d'ouverture, pour que `Journal` et `Memoire` partagent
 * la même connexion plutôt que d'en ouvrir chacune la leur sur le même fichier.
 *
 * `actu.sqlite` (la collecte) a sa propre porte : `Base`, une par instance,
 * puisque la veille se resynchronise à chaque contexte. Les deux ne se
 * mélangent pas ici — les croisements passeront par `ATTACH`, en lecture
 * seule, quand P3 en aura besoin (CLAUDE.md, règle 3).
 */
final class Db
{
    private static ?PDO $narh = null;

    public static function narh(): PDO
    {
        if (self::$narh instanceof PDO) {
            return self::$narh;
        }

        $pdo = new PDO('sqlite:' . narh_reglage('base'), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return self::$narh = $pdo;
    }
}
