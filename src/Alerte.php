<?php
declare(strict_types=1);

/**
 * Le niveau d'alerte d'une dépêche.
 *
 * Deux signaux, de nature différente, se cumulent :
 *
 * 1. **Le lexique** (ici) — ce que dit le titre. Rapide, mais faillible :
 *    « la mort du cinéma d'auteur » n'est pas une alerte.
 * 2. **La reprise** (Regroupeur, puis Collecteur) — combien de rédactions
 *    indépendantes titrent là-dessus dans le même quart d'heure. Lent d'une
 *    minute, mais c'est le signal fort : un fait qui déclenche cinq titres
 *    simultanés est un fait, pas une tournure.
 *
 * Le lexique seul ne dépasse jamais le seuil « urgent » : il faut la reprise.
 * C'est voulu — un titre isolé, si sombre soit-il, reste une dépêche.
 */
final class Alerte
{
    public const INFO   = 0;
    public const VEILLE = 1;
    public const ALERTE = 2;
    public const URGENT = 3;

    /** @var array<int, string> */
    public const NOMS = [
        self::INFO   => 'info',
        self::VEILLE => 'veille',
        self::ALERTE => 'alerte',
        self::URGENT => 'urgent',
    ];

    /** Plafond du lexique seul : le reste doit venir de la reprise. */
    private const PLAFOND_LEXIQUE = 9;

    /**
     * Motifs cherchés dans le titre normalisé (minuscules, sans accent, sans
     * ponctuation — voir Util::normaliser). Format : [regex, poids, libellé].
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const LEXIQUE = [
        /* -- Rupture : le fait est grave et daté ---------------------------- */
        ['/\battentats?\b|\bterroriste?s?\b/',                      5, 'attentat'],
        ['/\bfusillade|\btuerie\b|\bmassacre\b|\bcarnage\b/',        5, 'fusillade'],
        ['/\bprise d otages?\b|\botages?\b/',                        4, 'otages'],
        ['/\bexplosions?\b|\bdeflagration/',                         4, 'explosion'],
        ['/\bseismes?\b|\btremblement de terre\b|\btsunami\b/',      4, 'séisme'],
        ['/\bcrash\b|\bs est ecrase\b|\bcrashe\b|\bnaufrage\b/',     4, 'crash'],
        ['/\beffondrement d|\bderaillement\b|\beruption\b/',         4, 'catastrophe'],
        ['/\bassassinats?\b|\bassassine/',                           4, 'assassinat'],
        ['/\balerte enlevement\b|\betat d urgence\b/',               4, 'alerte officielle'],
        ['/\battaque au couteau\b|\battaque armee\b/',               4, 'attaque'],

        /* -- Gravité : conséquence humaine ou institutionnelle -------------- */
        ['/\bmorts?\b|\bmortelle?s?\b|\bdeces\b|\btuees?\b|\btues\b/', 3, 'décès'],
        ['/\bvictimes\b|\bblesses?\b|\bgrievement\b/',               3, 'victimes'],
        ['/\bincendies?\b|\bfeu de foret\b|\bbrasier\b/',            3, 'incendie'],
        // « frappe » est aussi un verbe courant — « une vague frappe le moteur »,
        // « la canicule frappe la France ». Au singulier, il faut le contexte ;
        // au pluriel, le mot n'est plus qu'un nom.
        ['/\bguerre\b|\bfrappes\b|\bfrappe (aerienne|russe|israelienne|americaine|ukrainienne|de drone)|\bbombardement|\bmissiles?\b|\binvasion\b/', 3, 'conflit'],
        ['/\bcoup d etat\b|\bputsch\b|\bdestitution\b/',             3, 'coup d\'État'],
        ['/\bdemissions?\b|\bdemissionne\b|\bmotion de censure\b|\bdissolution\b/', 3, 'crise politique'],
        ['/\bmis en examen\b|\bgarde a vue\b|\bcondamne\b|\binterpelle/', 3, 'judiciaire'],
        ['/\bevacuations?\b|\bevacues?\b|\bconfinement\b/',          3, 'évacuation'],
        ['/\bcyberattaques?\b|\brancongiciel\b|\bransomware\b/',     3, 'cyberattaque'],
        ['/\bepidemie\b|\bvigilance rouge\b|\binondations\b|\bcyclone\b|\bouragan\b/', 3, 'risque majeur'],
        ['/\benlevement\b|\bdisparition inquietante\b|\bfeminicide\b/', 3, 'disparition'],

        /* -- Signal de rédaction : le fil se tend --------------------------- */
        ['/\burgent\b|\bflash\b|\bbreaking\b/',                      3, 'urgent'],
        ['/\ben direct\b|\bsuivez\b|\bminute par minute\b/',         2, 'direct'],
        ['/\balertes?\b/',                                           2, 'alerte'],
        ['/\brevendique\b|\bconfirme\b|\bdement\b|\bannonce\b/',     2, 'annonce'],
        ['/\bexclusif\b|\bselon nos informations\b|\binformation\b/', 2, 'exclusivité'],
        ['/\bproces\b|\bverdict\b|\bperquisition\b|\bplainte\b/',    2, 'procédure'],
        ['/\bgreves?\b|\bblocages?\b|\bpannes?\b|\bmanifestations?\b/', 2, 'mobilisation'],
        ['/\bkrach\b|\bfaillite\b|\bplan social\b|\blicenciements\b/', 2, 'économie'],
        ['/\bvigilance orange\b|\bcanicule\b|\bsecheresse\b|\btempete\b/', 2, 'météo'],
        ['/\bsanctions\b|\bultimatum\b|\bcessez le feu\b|\bremaniement\b/', 2, 'diplomatie'],
        ['/\brappel (massif |produit )?\b|\bintoxication\b|\bcontamination\b/', 2, 'sanitaire'],

        /* -- Frémissement --------------------------------------------------- */
        ['/\bpolemique\b|\btensions?\b|\bmenaces?\b|\bcrise\b/',     1, 'tension'],
        ['/\breunion d urgence\b|\bconseil de defense\b|\bcellule de crise\b/', 3, 'cellule de crise'],
        ['/\benquete\b|\bavertissement\b|\bmise en garde\b/',        1, 'enquête'],
    ];

    /**
     * Ce qui n'est jamais une alerte, quel que soit le reste du titre. Un
     * « bon plan » avec le mot « explosion » dedans doit sortir du fil.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const REPOUSSOIRS = [
        ['/\bbon plan\b|\bcode promo\b|\bsoldes\b|\bblack friday\b|\bpromo\b/', 6, 'promotion'],
        ['/\bnotre avis\b|\bon a teste\b|\bnotre test\b|\bcomparatif\b|\bmeilleurs? \w+ de\b/', 5, 'banc d\'essai'],
        ['/\bhoroscope\b|\bmots fleches\b|\bsudoku\b|\bprogramme tv\b|\bresultats du loto\b/', 8, 'divertissement'],
        ['/\brecette\b|\bbande annonce\b|\bstreaming\b|\bspoiler\b|\bsaison \d\b/', 5, 'culture-loisirs'],
        ['/\bhoroscope\b|\bquiz\b|\bjeu concours\b/',                            5, 'jeu'],
    ];

    /**
     * Score lexical d'une dépêche.
     *
     * @return array{score: int, motifs: list<string>}
     */
    public static function lexique(string $titre, string $resume = ''): array
    {
        $t = ' ' . Util::normaliser($titre) . ' ';
        $r = ' ' . Util::normaliser(Util::tronquer($resume, 300)) . ' ';

        $score = 0;
        $motifs = [];

        foreach (self::LEXIQUE as [$regex, $poids, $libelle]) {
            if (preg_match($regex, $t) === 1) {
                $score += $poids;
                $motifs[$libelle] = true;
            } elseif ($poids >= 3 && preg_match($regex, $r) === 1) {
                // Dans le résumé, le mot pèse moins : il y a plus de place pour
                // une tournure et moins d'intention éditoriale.
                $score += 1;
                $motifs[$libelle] = true;
            }
        }

        // La capitale est un choix de rédaction, pas une casse au hasard :
        // « URGENT », « ALERTE INFO », « DIRECT » en tête de titre.
        if (preg_match('/^\s*[\[(]?\s*(URGENT|ALERTE|FLASH|BREAKING|DIRECT|INFO [A-ZÉÈ]{2,})\b/u', $titre) === 1) {
            $score += 3;
            $motifs['titre en capitales'] = true;
        }

        $score = min($score, self::PLAFOND_LEXIQUE);

        foreach (self::REPOUSSOIRS as [$regex, $poids, $libelle]) {
            if (preg_match($regex, $t) === 1) {
                $score -= $poids;
                $motifs[$libelle] = true;
            }
        }

        return ['score' => max(0, $score), 'motifs' => array_keys($motifs)];
    }

    /**
     * Score final → niveau. Les seuils vivent dans config/reglages.php : c'est
     * le seul réglage qu'on retouche vraiment à l'usage.
     */
    public static function niveau(int $score): int
    {
        if ($score >= (int) narh_reglage('seuil_urgent', 10)) {
            return self::URGENT;
        }
        if ($score >= (int) narh_reglage('seuil_alerte', 6)) {
            return self::ALERTE;
        }
        if ($score >= (int) narh_reglage('seuil_veille', 3)) {
            return self::VEILLE;
        }

        return self::INFO;
    }

    public static function nom(int $niveau): string
    {
        return self::NOMS[$niveau] ?? 'info';
    }
}
