<?php
declare(strict_types=1);

/**
 * Outils de texte, d'URL et de temps.
 *
 * Tout ce qui est ici est sans état : aucune classe ne dépend d'une autre pour
 * normaliser un titre. C'est la brique que le regroupement, l'alerte et la vue
 * partagent — si deux d'entre elles normalisaient différemment, deux dépêches
 * identiques ne se rejoindraient jamais.
 */
final class Util
{
    /** Repli manuel : iconv//TRANSLIT dépend de la locale et ne tient pas sous Windows. */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /**
     * Mots vides. La liste est courte à dessein : elle ne retire que ce qui ne
     * distingue jamais deux dépêches. « Mort », « feu » ou « loi » sont courts
     * mais portent l'événement — ils restent.
     */
    private const VIDES = [
        'alors', 'apres', 'aussi', 'autre', 'autres', 'avant', 'avec', 'avoir',
        'beaucoup', 'cela', 'cent', 'ceux', 'chez', 'comme', 'contre', 'dans',
        'depuis', 'deux', 'dire', 'dont', 'elle', 'elles', 'encore', 'entre',
        'etre', 'faire', 'fait', 'faut', 'leur', 'leurs', 'mais', 'meme',
        'moins', 'notre', 'nous', 'plus', 'pour', 'pourquoi', 'pres', 'quand',
        'que', 'quel', 'quelle', 'quelles', 'quels', 'sans', 'selon', 'ses',
        'sont', 'sous', 'sur', 'tous', 'tout', 'toute', 'toutes', 'trois',
        'tres', 'une', 'vers', 'video', 'voici', 'voir', 'vont', 'vous',
        'ainsi', 'aucun', 'cette', 'ceci', 'donc', 'ils', 'lors', 'peut',
        'point', 'reste', 'suite', 'ete', 'avait', 'cet',
        // Trois lettres, aucun pouvoir séparateur : gardés, ils gonflent le
        // dénominateur de Jaccard et suffisent à eux seuls à faire les deux
        // mots communs qui déclenchent une comparaison.
        'les', 'des', 'ces', 'son', 'par', 'aux', 'qui', 'pas', 'est', 'ont',
        'ans', 'ils', 'ceux', 'dun', 'dune', 'sera', 'font', 'veut', 'doit',
    ];

    /* ---- Texte ----------------------------------------------------------- */

    public static function sansAccents(string $texte): string
    {
        return strtr(mb_strtolower($texte), self::ACCENTS);
    }

    /** Minuscules, sans accent, sans ponctuation, espaces réduits. */
    public static function normaliser(string $texte): string
    {
        $texte = self::sansAccents($texte);
        $texte = preg_replace('/[^a-z0-9]+/u', ' ', $texte) ?? '';

        return trim(preg_replace('/\s+/', ' ', $texte) ?? '');
    }

    /**
     * Les mots significatifs d'un titre — la signature qui sert à rapprocher
     * deux dépêches. Dédoublonnés, triés : deux titres qui disent la même chose
     * dans un ordre différent donnent le même ensemble.
     *
     * @return list<string>
     */
    public static function jetons(string $texte): array
    {
        $mots = explode(' ', self::normaliser($texte));
        $gardes = [];

        foreach ($mots as $mot) {
            if ($mot === '' || in_array($mot, self::VIDES, true)) {
                continue;
            }
            // Un nombre isolé n'identifie rien ; une année, si.
            if (ctype_digit($mot) && strlen($mot) !== 4) {
                continue;
            }
            if (mb_strlen($mot) < 3) {
                continue;
            }
            $gardes[$mot] = true;
        }

        $gardes = array_keys($gardes);
        sort($gardes);

        return $gardes;
    }

    /**
     * Recouvrement de deux ensembles de mots, entre 0 et 1.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $commun = count(array_intersect($a, $b));

        return $commun === 0 ? 0.0 : $commun / (count($a) + count($b) - $commun);
    }

    /**
     * HTML d'un résumé de flux → texte brut sur une ligne.
     *
     * Chaque balise devient une espace, pas rien. strip_tags les efface, et un
     * résumé d'agrégateur — une liste de liens — ressort recollé : « des
     * touristes évacués Le Monde.frPrès de 300 arrestations ». Les mots soudés
     * faussent ensuite le découpage en jetons, donc le rapprochement.
     */
    public static function texte(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('/<[^>]*>/u', ' ', $html) ?? $html;
        // Filet pour un balisage mal fermé, que la passe précédente laisse entier.
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = str_replace(["\u{00a0}", "\u{200b}"], [' ', ''], $texte);

        return trim(preg_replace('/\s+/u', ' ', $texte) ?? '');
    }

    public static function tronquer(string $texte, int $longueur): string
    {
        if (mb_strlen($texte) <= $longueur) {
            return $texte;
        }
        $coupe = mb_substr($texte, 0, $longueur - 1);
        $espace = mb_strrpos($coupe, ' ');
        if ($espace !== false && $espace > $longueur * 0.6) {
            $coupe = mb_substr($coupe, 0, $espace);
        }

        return rtrim($coupe, " ,;:.-") . '…';
    }

    /* ---- URL ------------------------------------------------------------- */

    public static function domaine(string $url): string
    {
        $hote = parse_url($url, PHP_URL_HOST);

        return is_string($hote) ? preg_replace('/^www\./', '', $hote) ?? $hote : '';
    }

    /**
     * Forme canonique d'un lien : c'est elle qui sert de clé de dédoublonnage.
     * Les paramètres de campagne changent d'un flux à l'autre pour un même
     * article — les garder ferait entrer trois fois la même dépêche.
     */
    public static function canoniser(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['host'])) {
            return trim($url);
        }

        $hote = strtolower(preg_replace('/^www\./', '', $parts['host']) ?? $parts['host']);
        $chemin = rtrim($parts['path'] ?? '/', '/');
        if ($chemin === '') {
            $chemin = '/';
        }

        $requete = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $args);
            foreach (array_keys($args) as $cle) {
                $c = strtolower((string) $cle);
                if (str_starts_with($c, 'utm_') || in_array($c, [
                    'xtor', 'xtref', 'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref',
                    'at_medium', 'at_campaign', 'at_custom1', 'at_custom2',
                    'origin', 'ico', 'from', 's', 'cmpid', 'sh',
                ], true)) {
                    unset($args[$cle]);
                }
            }
            if ($args !== []) {
                ksort($args);
                $requete = '?' . http_build_query($args);
            }
        }

        return $hote . $chemin . $requete;
    }

    public static function absolu(string $lien, string $base): string
    {
        $lien = trim($lien);
        if ($lien === '' || preg_match('#^https?://#i', $lien)) {
            return $lien;
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return $lien;
        }
        $racine = $parts['scheme'] . '://' . $parts['host'];

        return str_starts_with($lien, '/') ? $racine . $lien : $racine . '/' . ltrim($lien, './');
    }

    /* ---- Temps ----------------------------------------------------------- */

    /**
     * Date d'un flux → horodatage. Retourne null plutôt qu'une date fausse :
     * une date au futur ou antérieure à une semaine est une erreur d'éditeur,
     * et le collecteur retombera alors sur l'heure de réception.
     */
    public static function horodatage(?string $date, ?int $maintenant = null): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $maintenant ??= time();
        $ts = strtotime(trim($date));
        if ($ts === false) {
            return null;
        }
        if ($ts > $maintenant + 3600 || $ts < $maintenant - 30 * 86400) {
            return null;
        }

        return $ts;
    }

    /** « il y a 3 min », « il y a 2 h », « hier 14:02 ». */
    public static function age(int $ts, ?int $maintenant = null): string
    {
        $delta = ($maintenant ?? time()) - $ts;

        if ($delta < 45) {
            return "à l'instant";
        }
        if ($delta < 3600) {
            return 'il y a ' . (int) round($delta / 60) . ' min';
        }
        if ($delta < 86400) {
            return 'il y a ' . (int) floor($delta / 3600) . ' h';
        }
        if ($delta < 172800) {
            return 'hier ' . date('H:i', $ts);
        }

        return date('d/m H:i', $ts);
    }

    /** Durée courte pour un compteur : 41ms, 1.2s. */
    public static function duree(int $ms): string
    {
        return $ms < 1000 ? $ms . 'ms' : number_format($ms / 1000, 1, ',', '') . 's';
    }

    /**
     * L'écart entre deux instants, en clair : « +12 min », « +1 h 30 ».
     *
     * `duree()` compte des millisecondes de requête, `age()` compte depuis
     * maintenant : ni l'un ni l'autre ne dit le temps qui sépare deux dépêches.
     */
    public static function ecart(int $secondes): string
    {
        if ($secondes < 60) {
            return 'même minute';
        }

        /* On arrondit d'abord, on découpe ensuite : tester les bornes sur les
           secondes brutes ferait déborder l'arrondi dans l'unité du dessus —
           59 min 59 s s'affichait « +60 min », et 23 h 59 min « +24 h ». */
        $minutes = (int) round($secondes / 60);
        if ($minutes < 60) {
            return '+' . $minutes . ' min';
        }

        $heures = intdiv($minutes, 60);
        $reste  = $minutes % 60;
        if ($heures < 24) {
            return '+' . $heures . ' h' . ($reste > 0 ? ' ' . $reste : '');
        }

        return '+' . intdiv($heures, 24) . ' j';
    }
}
