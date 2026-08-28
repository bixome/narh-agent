<?php
declare(strict_types=1);

/**
 * Le croisement avec des sources extérieures — ce que la collecte ne peut pas
 * savoir seule.
 *
 * NARH mesure la **reprise** : combien de rédactions françaises portent le même
 * fait. C'est une corroboration, pas une vérification — cinq maisons qui
 * republient la même dépêche d'agence ne l'ont pas vérifiée pour autant, elles
 * l'ont recopiée. Il manquait donc une classe de sources d'une autre nature :
 * un registre qui dit ce qui s'est produit, indépendamment de ce qu'on en écrit.
 *
 * Deux régimes, à ne jamais confondre à l'affichage :
 *
 * - **vérifier** — un registre indépendant confirme ou dément le fait lui-même
 *   (l'USGS pour un séisme : magnitude, heure, coordonnées) ;
 * - **corroborer** — d'autres en parlent, plus ou moins largement (GDELT).
 *
 * Trois règles de maison, et elles décident de toute l'architecture :
 *
 * 1. **Rien n'entre dans le score** (règle 4). Ce que dit un tiers s'affiche à
 *    côté, jamais dedans. La collecte ne pense pas, et elle ne pense pas
 *    davantage avec l'aide d'un tiers : le score doit rester reproductible hors
 *    ligne, ce qu'aucune réponse réseau ne garantit.
 * 2. **Jamais pendant l'affichage.** C'est la règle de la lecture hors réponse,
 *    et pour le direct elle est vitale : la contrainte fondatrice est de ne
 *    jamais laisser plus de dix-sept secondes de blanc, et un segment est
 *    composé en 30 à 45 ms précisément parce qu'il n'attend rien. Un appel
 *    réseau de deux secondes qui échoue n'entre pas dans ce budget. Le segment
 *    part donc **non vérifié**, et le verdict le rejoint après.
 * 3. **L'hôte vient de la configuration** (`Lecture::service()`), jamais d'une
 *    réponse ni d'une dépêche.
 *
 * La trace vit dans `narh.sqlite` avec le journal et l'antenne, pas dans la
 * collecte : c'est de la mémoire d'agent, et `Base` sait déjà la joindre en
 * lecture seule (`Base::traitement()`).
 */
final class Osint
{
    /** Ce qu'un verdict peut valoir. */
    public const CONCORDE = 'concorde';
    public const ECART    = 'ecart';
    public const ABSENT   = 'absent';
    public const CORROBORE = 'corrobore';

    /* Le plafond que GDELT applique de toute façon à une page de résultats.
       L'écrire ici sert à dire « 75+ » plutôt que « 75 » : un compte plafonné
       présenté comme exact serait faux, et toujours faux du même côté. */
    private const GDELT_MAX = 75;

    private static bool $prete = false;

    private static function db(): PDO
    {
        $pdo = Db::narh();

        if (!self::$prete) {
            /* `groupe_id` **et** `service` en clé : un même sujet peut être
               croisé par l'USGS et par GDELT, et les deux verdicts coexistent
               sans s'écraser. `charge` garde la réponse utile déjà résumée —
               refaire l'appel pour réafficher serait payer deux fois. */
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS osint_vu (
                    groupe_id INTEGER NOT NULL,
                    service   TEXT    NOT NULL,
                    quand     INTEGER NOT NULL,
                    verdict   TEXT    NOT NULL,
                    dit       TEXT    NOT NULL DEFAULT '',
                    PRIMARY KEY (groupe_id, service)
                );

                CREATE INDEX IF NOT EXISTS idx_osint_vu_quand ON osint_vu(quand DESC);
                SQL);
            self::$prete = true;
        }

        return $pdo;
    }

    /**
     * Les verdicts déjà rendus sur ces sujets.
     *
     * @param  list<int> $ids
     * @return array<int, list<array{service: string, verdict: string, dit: string, quand: int}>>
     */
    public static function connus(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $trous = implode(',', array_fill(0, count($ids), '?'));
        $st = self::db()->prepare(
            "SELECT groupe_id, service, verdict, dit, quand
             FROM osint_vu WHERE groupe_id IN ($trous) ORDER BY quand DESC"
        );
        $st->execute($ids);

        $par = [];
        foreach ($st->fetchAll() as $v) {
            $par[(int) $v['groupe_id']][] = [
                'service' => (string) $v['service'],
                'verdict' => (string) $v['verdict'],
                'dit'     => (string) $v['dit'],
                'quand'   => (int) $v['quand'],
            ];
        }

        return $par;
    }

    /** Un verdict déjà rendu ne se redemande pas : le réseau n'est pas idempotent. */
    public static function deja(int $groupeId, string $service): bool
    {
        $st = self::db()->prepare(
            'SELECT 1 FROM osint_vu WHERE groupe_id = :g AND service = :s LIMIT 1'
        );
        $st->execute(['g' => $groupeId, 's' => $service]);

        return $st->fetchColumn() !== false;
    }

    private static function retenir(int $groupeId, string $service, string $verdict, string $dit): void
    {
        $st = self::db()->prepare(
            'INSERT OR REPLACE INTO osint_vu (groupe_id, service, quand, verdict, dit)
             VALUES (:g, :s, :q, :v, :d)'
        );
        $st->execute([
            'g' => $groupeId, 's' => $service, 'q' => time(),
            'v' => $verdict, 'd' => $dit,
        ]);

        /* La chronologie unique (règle 7) : c'est le seul endroit où l'on verra
           « alerte à 04:30 → segment à 04:31 → USGS à 04:32 : concorde ». Un
           verdict qui ne s'écrirait que dans sa propre table rendrait la chaîne
           de vérification invisible à elle-même. */
        Journal::noter(
            $verdict === self::ECART ? 'warn' : 'info',
            'osint',
            $service . ' · ' . $verdict . ' · ' . $dit,
        );
    }

    /**
     * Croiser un sujet avec ce qui peut le vérifier.
     *
     * Rend `null` quand aucun service ne s'applique — ce qui est le cas le plus
     * fréquent, et n'est pas un échec : l'USGS n'a rien à dire d'une garde à
     * vue. Un service qui ne s'applique pas ne laisse aucune trace, sans quoi
     * le journal se remplirait de « rien à vérifier ».
     *
     * @param  array<string, mixed> $g un groupe rendu par Base
     * @return array{service: string, verdict: string, dit: string}|null
     */
    public static function croiser(array $g): ?array
    {
        $id = (int) ($g['id'] ?? 0);
        $titre = (string) ($g['titre'] ?? '');
        if ($id <= 0 || $titre === '') {
            return null;
        }

        $services = narh_reglage('osint');

        $quand = (int) ($g['dernier'] ?? time());
        $rendu = null;

        if (!empty($services['usgs']['actif']) && !self::deja($id, 'usgs')) {
            $v = self::usgs($titre, $quand, (string) $services['usgs']['url']);
            if ($v !== null) {
                self::retenir($id, 'usgs', $v['verdict'], $v['dit']);
                $rendu = ['service' => 'usgs'] + $v;
            }
        }

        /* Les deux services se suivent au lieu de s'exclure : l'un vérifie le
           fait, l'autre mesure sa diffusion, et un séisme mérite les deux. Le
           premier verdict rendu est celui qu'on retourne — l'appelant n'en fait
           qu'un compteur, et l'affichage relit `connus()`, qui les a tous. */
        if (!empty($services['gdelt']['actif']) && !self::deja($id, 'gdelt')) {
            $v = self::gdelt($titre, $quand, (string) $services['gdelt']['url']);
            if ($v !== null) {
                self::retenir($id, 'gdelt', $v['verdict'], $v['dit']);
                $rendu ??= ['service' => 'gdelt'] + $v;
            }
        }

        return $rendu;
    }

    /**
     * GDELT : combien de monde en parle, et depuis combien de pays.
     *
     * Ce service **corrobore**, il ne vérifie pas — la distinction est celle du
     * haut de ce fichier, et elle décide de tout ce qui suit. NARH compte les
     * rédactions françaises qui reprennent un sujet ; treize maisons françaises
     * et zéro couverture étrangère, ou trois françaises et deux cents mondiales,
     * ne sont pas le même événement, et le desk les affichait pareil.
     *
     * **La requête est en noms propres, et c'est une contrainte du service, pas
     * un choix.** Mesuré : GDELT indexe la traduction anglaise de ce qu'il
     * moissonne, si bien qu'un titre français ne lui dit rien — `crue Népal
     * morts` rend zéro article, `inondations` en rend un, et
     * `sourcelang:french inondations` zéro. Les noms propres, eux, traversent :
     * `Nepal` rend 75 articles dans 14 pays, `Gaza` 75 dans 15, `Caen` 75 dans
     * 14 dont la Grèce et le Portugal. Traduire le reste demanderait le modèle,
     * qui n'a rien à faire ici (règle 4) et encore moins pendant une passe.
     *
     * **Deux noms propres au minimum, et joints par un ET.** C'est la leçon de
     * `usgs()` juste en dessous, payée une fois déjà : un verdict affiché à côté
     * d'un titre est lu comme portant sur lui. Interroger `Gaza` seul rendrait
     * la couverture mondiale de Gaza en général et l'afficherait sous « une
     * quarantaine de Gazaouis évacués » — une fausse vérifiabilité, exactement
     * ce que cette classe existe pour combattre. L'intersection de deux noms,
     * dans la fenêtre du sujet, porte sur l'événement.
     *
     * **On ne dit rien quand on ne trouve rien.** Zéro article ne se distingue
     * pas d'une requête qui n'a pas su nommer le sujet : les deux causes
     * produisent le même silence, et une seule des deux serait une information.
     *
     * @return array{verdict: string, dit: string}|null
     */
    private static function gdelt(string $titre, int $quand, string $url): ?array
    {
        $noms = self::nomsPropres($titre);
        if (count($noms) < 2) {
            return null;
        }

        $noms = array_slice($noms, 0, 2);
        $requete = implode(' ', $noms);

        /* La fenêtre du sujet, pas les trois derniers jours : ce qu'on mesure
           est la diffusion **de cet événement**, et le même couple de noms
           propres reparaît sur d'autres faits d'un mois à l'autre. */
        $json = Lecture::service($url, [
            'query'         => $requete,
            'mode'          => 'artlist',
            'format'        => 'json',
            'maxrecords'    => self::GDELT_MAX,
            'sort'          => 'hybridrel',
            'startdatetime' => gmdate('YmdHis', $quand - 86400),
            'enddatetime'   => gmdate('YmdHis', $quand + 86400),
        ], 5);

        if ($json === null) {
            return null;
        }

        /* Une requête que GDELT refuse revient en texte, pas en JSON — trop
           courte, opérateur inconnu. `json_decode` rend null, et ce n'est pas
           une panne : c'est un titre dont on n'a pas su faire une question. */
        $data = json_decode($json, true);
        $articles = is_array($data['articles'] ?? null) ? $data['articles'] : [];
        if ($articles === []) {
            return null;
        }

        $pays = [];
        foreach ($articles as $a) {
            $p = trim((string) ($a['sourcecountry'] ?? ''));
            if ($p !== '') {
                $pays[$p] = true;
            }
        }

        /* Hors France, parce que c'est la seule chose que la reprise ne sait
           pas déjà dire : les maisons françaises, le desk les compte lui-même. */
        $etranger = count(array_diff_key($pays, ['France' => true]));

        $compte = count($articles);
        $dits = $compte >= self::GDELT_MAX ? self::GDELT_MAX . '+' : (string) $compte;

        /* La requête est **dite**, et ce n'est pas un détail d'affichage : sans
           elle, le lecteur ne peut pas savoir sur quoi porte le chiffre, et un
           chiffre dont on ignore l'objet se lit comme portant sur le titre
           entier. Le mot « corrobore » ouvre la ligne pour la même raison — le
           régime doit se lire avant le nombre. */
        return [
            'verdict' => self::CORROBORE,
            'dit'     => 'GDELT corrobore : ' . $dits . ' article' . ($compte > 1 ? 's' : '')
                . ', ' . match (true) {
                    $etranger === 0 => 'aucun pays hors France',
                    $etranger === 1 => '1 pays hors France',
                    default         => $etranger . ' pays hors France',
                }
                . ' — « ' . $requete . ' », 48 h',
        ];
    }

    /**
     * Les noms propres d'un titre, sans accent — la seule matière qu'un index
     * anglophone reconnaisse dans une dépêche française.
     *
     * Le premier mot est écarté : une phrase commence par une majuscule, et la
     * prendre pour un nom propre ferait de « Crue » un lieu. Les marqueurs de
     * rubrique des rédactions le sont aussi — `DIRECT.`, `VIDÉO.`, `RÉCIT` ne
     * qualifient pas le sujet mais la forme de l'article, et ils traînent en
     * tête d'un titre sur cinq.
     *
     * @return list<string>
     */
    private static function nomsPropres(string $titre): array
    {
        /* Les mots grammaticaux qui prennent la majuscule en français, et les
           marqueurs de rubrique. Une capitale n'y suffit pas à faire un nom. */
        static $creux = [
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'ce', 'cet',
            'cette', 'ces', 'il', 'elle', 'ils', 'elles', 'on', 'et', 'mais',
            'car', 'pour', 'sur', 'dans', 'avec', 'sans', 'apres', 'avant',
            'comment', 'pourquoi', 'quand', 'qui', 'que', 'quoi', 'plus',
            'moins', 'tout', 'tous', 'deux', 'trois', 'entre', 'chez', 'vers',
            'selon', 'direct', 'video', 'recit', 'reportage', 'enquete',
            'portrait', 'analyse', 'decryptage', 'temoignage', 'info', 'exclusif',
            'edito', 'tribune', 'live', 'suivez', 'revivez',
        ];

        $mots = preg_split('/[^\p{L}\p{N}\'’-]+/u', $titre, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $noms = [];

        foreach ($mots as $rang => $mot) {
            /* L'élision colle le déterminant au nom : « l’Himalaya » est un
               seul mot pour qui découpe sur la ponctuation, et l'apostrophe le
               faisait rejeter plus bas. Mesuré sur le desk : trois titres sur
               quatorze perdaient ainsi leur seul nom propre. */
            $mot = preg_replace('/^(?:[ldnmtscj]|qu|jusqu|lorsqu|puisqu)[\'’]/iu', '', $mot) ?? $mot;

            if ($rang === 0 || mb_strlen($mot) < 3) {
                continue;
            }
            /* `mb_substr` et non `$mot[0]` : un « É » tient sur deux octets.
               Et la majuscule se teste par l'existence d'une minuscule
               différente, sans quoi « 500 » passerait pour un nom propre —
               un chiffre est égal à lui-même dans les deux casses. */
            $tete = mb_substr($mot, 0, 1);
            if (mb_strtolower($tete) === $tete) {
                continue;
            }

            $plat = Util::sansAccents($mot);
            if (in_array($plat, $creux, true) || preg_match('/^[a-z0-9]+$/', $plat) !== 1) {
                continue;
            }

            $noms[$plat] = true;
        }

        return array_keys($noms);
    }

    /**
     * L'USGS : le fait lui-même, pas ce qu'on en écrit.
     *
     * Le seul service de cette classe qui **vérifie**. Un titre annonce « un
     * séisme de magnitude 6,7 au Pérou » ; le catalogue sismique dit s'il y a
     * eu un séisme, à quelle heure, et de quelle magnitude. Les trois réponses
     * possibles ont chacune une valeur propre — y compris « aucun séisme
     * correspondant », qui est une information et non une panne.
     *
     * On ne cherche que si le titre parle de séisme : interroger un catalogue
     * sismique sur une garde à vue coûterait un aller-retour réseau par ligne
     * du desk pour n'apprendre jamais rien.
     *
     * La fenêtre est large — un jour de part et d'autre — parce qu'une dépêche
     * peut relater un séisme de la veille, et que l'heure d'un flux RSS n'est
     * pas celle de l'événement. Le rapprochement se fait sur la **magnitude**,
     * seule grandeur que le titre et le catalogue expriment de la même façon.
     *
     * @return array{verdict: string, dit: string}|null
     */
    private static function usgs(string $titre, int $quand, string $url): ?array
    {
        $t = mb_strtolower($titre);
        if (!preg_match('/\b(séisme|seisme|tremblement de terre|magnitude)\b/u', $t)) {
            return null;
        }

        // « magnitude 6,7 » — la virgule décimale française, et le point.
        $annoncee = preg_match('/magnitude\s+de?\s*([0-9]+)[.,]([0-9])/u', $t, $m) === 1
            ? (float) ($m[1] . '.' . $m[2])
            : null;

        $json = Lecture::service($url, [
            'format'       => 'geojson',
            'starttime'    => gmdate('Y-m-d', $quand - 86400),
            'endtime'      => gmdate('Y-m-d', $quand + 86400),
            'minmagnitude' => 4.5,
            'orderby'      => 'magnitude',
            'limit'        => 20,
        ]);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        $evenements = is_array($data['features'] ?? null) ? $data['features'] : [];

        /* « Absent » ne se prononce que si le titre annonçait une magnitude :
           sans elle, on ne sait pas ce qu'on cherchait, donc pas davantage
           qu'on ne l'a pas trouvé. */
        if ($evenements === []) {
            return $annoncee === null
                ? null
                : ['verdict' => self::ABSENT, 'dit' => 'aucun séisme M ' . $annoncee . ' au catalogue'];
        }

        /* Sans magnitude annoncée, **on ne dit rien**.
           Le repli précédent rapportait le séisme le plus fort de la fenêtre,
           quel que soit son lieu — « ce qui situe sans prétendre confirmer ».
           C'était faux, et mesuré : quatre titres différents ont reçu le même
           verdict « M 6 — 145 km N of Caluula, Somalia », dont « Beyoncé fait
           un don pour les sinistrés » et « **Risque** de grand séisme en
           Espagne », un séisme hypothétique appuyé par un séisme réel à six
           mille kilomètres.
           Un verdict qui s'affiche à côté d'un titre est lu comme portant sur
           lui. Fabriquer cette proximité, c'est produire exactement la fausse
           vérifiabilité que toute cette classe existe pour combattre — et c'est
           pire que de ne rien afficher, parce que ça se lit comme une preuve. */
        if ($annoncee === null) {
            return null;
        }

        $meilleur = null;
        foreach ($evenements as $e) {
            $mag = (float) ($e['properties']['mag'] ?? 0);
            $ecart = abs($mag - $annoncee);
            if ($meilleur === null || $ecart < $meilleur['ecart']) {
                $meilleur = ['ecart' => $ecart, 'mag' => $mag, 'p' => $e['properties'] ?? []];
            }
        }

        $lieu = (string) ($meilleur['p']['place'] ?? '?');
        $heure = isset($meilleur['p']['time'])
            ? gmdate('d/m H:i', (int) ($meilleur['p']['time'] / 1000)) . ' UTC'
            : '?';

        /* Un dixième de tolérance : les magnitudes sont révisées à la hausse ou
           à la baisse dans les heures qui suivent, et une dépêche fige celle
           qu'elle a vue. Au-delà, ce n'est plus une révision mais un écart, et
           il mérite d'être dit — c'est même le cas le plus utile de tout ce
           fichier. */
        return abs($meilleur['mag'] - $annoncee) <= 0.15
            ? [
                'verdict' => self::CONCORDE,
                'dit'     => 'USGS : M ' . $meilleur['mag'] . ' — ' . $lieu . ', ' . $heure,
            ]
            : [
                'verdict' => self::ECART,
                'dit'     => 'le titre dit M ' . $annoncee . ', l’USGS M ' . $meilleur['mag']
                    . ' — ' . $lieu . ', ' . $heure,
            ];
    }
}
