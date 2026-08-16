<?php
declare(strict_types=1);

/**
 * Le direct — NARH qui parle sans qu'on demande.
 *
 * C'est la boucle : la veille déclenche la production, et la production laisse
 * une trace. Jusqu'ici NARH répondait ; ici il tient l'antenne.
 *
 * **La contrainte fondatrice : jamais plus de 17 secondes de blanc.** Tout le
 * reste en découle. Un modèle local met plusieurs secondes à répondre et peut
 * être déchargé, injoignable ou lent : on ne peut donc pas fonder un direct sur
 * lui. Chaque segment est **composé en PHP depuis la veille**, en quelques
 * millisecondes, et la garantie tient sans dépendre de personne. Ce que le
 * modèle ajouterait — une reformulation, un commentaire — est un enrichissement
 * possible, jamais la source du flux.
 *
 * Cinq natures de segment, et l'ordre entre elles fait la conduite :
 *
 * | | |
 * |---|---|
 * | `alerte`  | Un événement de niveau ≥ alerte jamais passé. **Préempte tout.** |
 * | `depeche` | Le sujet le plus récent jamais passé. |
 * | `bref`    | Trois titres d'une rubrique, en rotation — local, national, thématique. |
 * | `point`   | Une synthèse de ce qui domine, périodiquement. |
 * | `relance` | Rien de neuf : un sujet déjà passé, repris sous un autre angle. |
 *
 * `relance` est ce qui rend le direct tenable : une nuit calme ne produit pas
 * de dépêche neuve pendant vingt minutes, et il faut pourtant parler. Sans
 * elle, le direct s'arrêterait au premier creux — le seul moment où il a
 * vraiment besoin d'exister.
 *
 * **Ne pas se répéter** est tenu par une mémoire d'antenne (`direct_vu`) : un
 * sujet passé ne repasse pas, sauf en relance, après un délai, et en le disant.
 */
final class Direct
{
    /** Le blanc maximal toléré, en secondes. Tout le reste en découle. */
    public const BUDGET = 17;

    /** Un sujet déjà passé ne revient pas avant ce délai, et seulement en relance. */
    private const REPRISE_APRES = 600;

    /** Une synthèse tous les N segments : plus souvent, elle redirait le flux. */
    private const POINT_TOUS_LES = 7;

    /** Les rubriques du tour d'antenne — l'ordre est celui d'un journal parlé. */
    private const ROTATION = ['une', 'france', 'monde', 'eco', 'tech', 'regional'];

    /**
     * Les lancements, par nature.
     *
     * Un panel, pas une phrase : la même formule répétée toutes les dix-sept
     * secondes s'entend au bout de trois tours. Le choix se fait sur le rang du
     * segment, pas au hasard — un direct rejoué doit se dérouler à l'identique.
     */
    private const LANCEMENTS = [
        'alerte'  => ['Information NARH', 'Alerte info', 'Dernière minute'],
        'depeche' => ['On y vient', 'À signaler', "L'info qui tombe", 'Autre sujet'],
        'bref'    => ['En bref', 'Le tour des titres', 'Ailleurs dans l’actualité', 'On résume'],
        'point'   => ['Le point', 'Où en est-on', 'Ce qui domine', 'Récapitulons'],
        'relance' => ['On y revient', 'Suite de ce sujet', 'Pour y revenir', 'Rappel'],
    ];

    private static bool $prete = false;

    private static function pdo(): PDO
    {
        $pdo = Db::narh();

        if (!self::$prete) {
            $pdo->exec(<<<'SQL'
                /* La mémoire d'antenne : ce qui est déjà passé, et combien de
                   fois. Sans elle, le direct rejouerait la même dépêche à
                   chaque tour dès qu'elle est la plus récente. */
                CREATE TABLE IF NOT EXISTS direct_vu (
                    groupe_id INTEGER PRIMARY KEY,
                    quand     INTEGER NOT NULL,
                    fois      INTEGER NOT NULL DEFAULT 1
                );

                CREATE TABLE IF NOT EXISTS direct_etat (
                    cle    TEXT PRIMARY KEY,
                    valeur TEXT NOT NULL
                );
                SQL);
            self::$prete = true;
        }

        return $pdo;
    }

    private static function etat(string $cle, string $defaut = '0'): string
    {
        $st = self::pdo()->prepare('SELECT valeur FROM direct_etat WHERE cle = ?');
        $st->execute([$cle]);
        $v = $st->fetchColumn();

        return $v === false ? $defaut : (string) $v;
    }

    private static function poserEtat(string $cle, string $valeur): void
    {
        self::pdo()->prepare(
            'INSERT INTO direct_etat (cle, valeur) VALUES (:c, :v)
             ON CONFLICT(cle) DO UPDATE SET valeur = :v'
        )->execute(['c' => $cle, 'v' => $valeur]);
    }

    /**
     * L'antenne est l'état **par défaut**.
     *
     * NARH est branché sur un flux dense qui arrive qu'on le regarde ou non :
     * ouvrir l'écran sur un champ muet, c'est ignorer ce qui vient d'arriver et
     * demander à l'utilisateur de réclamer ce qu'il aurait dû voir. Il faut donc
     * une décision explicite pour **couper** l'antenne, pas pour l'ouvrir.
     *
     * La conversation ne la coupe pas : elle la préempte le temps d'un échange
     * puis lui rend la main (voir `libs/js/narh.js`, § Le régime automatique).
     */
    public static function enAntenne(): bool
    {
        return self::etat('antenne', '1') === '1';
    }

    /**
     * Ouvrir l'antenne si personne ne l'a explicitement coupée.
     *
     * Appelé au rendu : le premier écran d'une journée trouve l'antenne
     * fermée en base (rien n'a jamais été écrit) et doit néanmoins diffuser.
     */
    public static function amorcer(): void
    {
        if (self::etat('antenne', '') === '') {
            self::demarrer();
        }
    }

    /**
     * Ouvrir l'antenne.
     *
     * La mémoire d'antenne n'est pas effacée : reprendre un direct dix minutes
     * après l'avoir coupé ne doit pas rejouer ce qui vient de passer.
     */
    public static function demarrer(): void
    {
        self::poserEtat('antenne', '1');
        self::poserEtat('debut', (string) time());
        self::poserEtat('segments', '0');
        self::poserEtat('voix_jetons', '0');
        // Les formulations récentes, en revanche, ne s'oublient pas : rouvrir
        // l'antenne ne doit pas autoriser à redire ce qu'on vient de dire.
        Journal::noter('ok', 'direct', 'antenne ouverte');
    }

    /**
     * Fermer l'antenne, et rendre la note de quart.
     *
     * C'est le second livrable de la boucle, et il tombe naturellement ici :
     * ce qui vient d'être dit à l'antenne est exactement ce qu'il faut
     * transmettre à qui prend la suite. La note entre dans le fil comme un tour
     * — elle se relit, se cite, et survit à la session.
     *
     * @return array{segments: int, sujets: int, duree: int, couvert: list<array<string, mixed>>}
     */
    public static function arreter(): array
    {
        $debut = (int) self::etat('debut', (string) time());
        $segments = (int) self::etat('segments');
        self::poserEtat('antenne', '0');

        $st = self::pdo()->prepare('SELECT groupe_id, fois FROM direct_vu WHERE quand >= ? ORDER BY quand DESC');
        $st->execute([$debut]);
        $vus = $st->fetchAll();

        /* Un par un, et plafonné à douze : `Base::clauses()` filtre sur un
           groupe, pas sur une liste, et élargir la base pour un seul appel de
           fin d'antenne coûterait plus cher que douze requêtes qui ne partent
           qu'une fois. Une note de quart qui listerait quarante sujets ne se
           lirait pas de toute façon. */
        $couvert = [];
        if ($vus !== []) {
            $base = new Base((string) narh_reglage('base_veille'));
            foreach (array_slice($vus, 0, 12) as $v) {
                $groupes = $base->arbre(['groupe' => (int) $v['groupe_id']], 1);
                if ($groupes !== []) {
                    $couvert[] = $groupes[0] + ['fois' => (int) $v['fois']];
                }
            }
        }

        $bilan = [
            'segments' => $segments,
            'sujets'   => count($vus),
            'duree'    => max(0, time() - $debut),
            'jetons'   => (int) self::etat('voix_jetons'),
            'couvert'  => $couvert,
        ];

        Journal::noter('ok', 'direct', sprintf(
            'antenne fermée : %d segments, %d sujets, %d jetons, %s',
            $bilan['segments'],
            $bilan['sujets'],
            $bilan['jetons'],
            Util::duree($bilan['duree'] * 1000),
        ));

        return $bilan;
    }

    /**
     * Le prochain segment.
     *
     * Composé, pas généré : la sélection est reproductible et instantanée.
     * L'ordre des cas *est* la conduite éditoriale — l'alerte passe avant le
     * neuf, le neuf avant le tour des titres, et la relance ne sert que quand
     * il n'y a rien d'autre.
     *
     * @return array{nature: string, lancement: string, texte: string, pieces: list<Piece>, ids: list<int>}
     */
    public static function prochain(): array
    {
        $base = new Base((string) narh_reglage('base_veille'));
        $rang = (int) self::etat('segments');
        $maintenant = time();
        $vus = self::vus();

        $segment = self::alerte($base, $vus, $maintenant)
            ?? self::point($base, $rang)
            ?? self::depeche($base, $vus)
            ?? self::bref($base, $vus, $rang)
            ?? self::relance($base, $maintenant);

        // Le rang sert au choix du lancement : il ne doit avancer qu'une fois
        // le segment retenu, sinon les formules sauteraient à chaque essai.
        self::poserEtat('segments', (string) ($rang + 1));
        self::marquerVus($segment['ids'], $maintenant);

        $pool = self::LANCEMENTS[$segment['nature']] ?? ['À signaler'];
        $segment['lancement'] = $pool[$rang % count($pool)];
        $segment['rang'] = $rang;

        /* La matière du segment est mise de côté pour la voix : celle-ci est
           demandée ensuite, dans un second appel, et le serveur doit savoir de
           quoi il vient de parler sans que le navigateur le lui réexplique. */
        self::poserEtat('dernier', (string) json_encode([
            'rang'    => $rang,
            'nature'  => $segment['nature'],
            'texte'   => $segment['texte'],
            'titres'  => array_map(
                static fn (Piece $p): string => $p->titre,
                array_slice($segment['pieces'], 0, 3),
            ),
        ], JSON_UNESCAPED_UNICODE));

        Journal::noter('info', 'direct', $segment['nature'] . ' : ' . mb_strimwidth($segment['texte'], 0, 70, '…'));

        return $segment;
    }

    /* ---- La voix -------------------------------------------------------------
       Le modèle commente ce qui vient de passer à l'antenne. Il n'est jamais sur
       le chemin critique : le segment est déjà affiché quand la voix est
       demandée, et s'il ne répond pas, il ne manque rien.

       C'est la règle 4 transposée : la collecte ne pense pas, et ici le modèle
       ne décide pas de ce qui passe — il commente ce que le lexique et la
       reprise ont retenu. */

    /** Combien de formulations récentes on lui interdit de refaire. */
    private const VOIX_MEMOIRE = 4;

    /** Le délai au-delà duquel la voix ne vaut plus rien : le segment est vieux. */
    private const VOIX_DELAI = 9;

    /**
     * Une phrase sur le dernier segment composé, ou rien.
     *
     * @return array{rang: int, texte: string, jetons: int}|null
     */
    public static function voix(): ?array
    {
        $dernier = json_decode(self::etat('dernier', '{}'), true);
        if (!is_array($dernier) || ($dernier['texte'] ?? '') === '') {
            return null;
        }

        $reglages = Agent::reglages();
        $ollama = new Ollama((string) narh_reglage('ollama')['url']);

        $recentes = json_decode(self::etat('voix_recentes', '[]'), true);
        $recentes = is_array($recentes) ? $recentes : [];

        /* La consigne est étroite, et elle a été resserrée après mesure.
           Première version, on demandait de « commenter » : le modèle ajoutait
           des faits absents du titre — « les équipes se tiennent prêtes », « une
           soirée difficile ». Un 3B à qui l'on demande un commentaire comble le
           vide avec ce qu'il croit savoir, et dans un journal c'est une faute
           grave, pas une maladresse.

           On ne lui demande donc plus de commenter mais de **reformuler** : la
           tâche ne laisse rien à inventer, puisque toute la matière est déjà
           dans la phrase qu'on lui donne. */
        $consigne = "Tu es la voix d'un journal en direct. On te donne un titre d'actualité déjà vérifié. "
            . "Reformule-le en UNE phrase courte (25 mots maximum), en français, sur un ton de présentateur "
            . "sobre, comme une annonce à l'antenne.\n"
            . "INTERDIT : ajouter un fait, un chiffre, un lieu, un nom, une conséquence ou une réaction qui "
            . "ne soit pas littéralement dans le titre. Tu n'informes pas, tu annonces.\n"
            . "Si tu ne peux rien dire sans inventer, écris une simple transition neutre.\n"
            . 'Pas de guillemets, pas de liste, pas de préambule.';

        if ($recentes !== []) {
            $consigne .= "\nTu viens de dire : « " . implode(' » « ', $recentes) . " ». Ne reprends ni ces "
                . 'formulations ni leur structure.';
        }

        $matiere = match ($dernier['nature']) {
            'bref'    => "Rubrique : {$dernier['texte']}. Titres : " . implode(' / ', $dernier['titres'] ?? []),
            'point'   => 'Synthèse de ' . $dernier['texte'] . '. Sujets : ' . implode(' / ', $dernier['titres'] ?? []),
            'relance' => 'On revient sur ce sujet déjà traité : ' . $dernier['texte'],
            default   => $dernier['texte'],
        };

        $sortie = $ollama->phrase(
            (string) $reglages['modele'],
            [
                ['role' => 'system', 'content' => $consigne],
                ['role' => 'user', 'content' => $matiere],
            ],
            (float) $reglages['temperature'],
            self::VOIX_DELAI,
            60,
        );

        if ($sortie === null) {
            Journal::noter('warn', 'direct', 'voix muette (modèle indisponible ou trop lent)');

            return null;
        }

        // Une phrase, pas un paragraphe : le modèle déborde, on coupe au premier
        // point plutôt que d'afficher ce qu'il a commencé à dérouler.
        $texte = trim(strtr($sortie['texte'], ["\n" => ' ', '"' => '', '«' => '', '»' => '']));
        if (preg_match('/^(.{20,}?[.!?])\s/u', $texte . ' ', $m) === 1) {
            $texte = trim($m[1]);
        }
        $texte = mb_strimwidth($texte, 0, 220, '…');

        /* Le garde-fou des chiffres.

           Mesuré : sur « Le Japon fête les **dix** ans de Pokémon GO », le
           modèle a annoncé « depuis **cinq** ans ». Aucune consigne ne corrige
           cela — un 3B reformule les nombres comme il reformule les mots. Une
           consigne se plaide, une vérification se prouve : toute quantité de la
           voix doit se retrouver dans la matière, chiffres et nombres écrits en
           lettres compris. Sinon la phrase saute, et le segment reste factuel.

           On préfère une voix muette à une voix fausse : le direct tient sans
           elle, il ne tient pas avec un chiffre inventé. */
        if (self::inventeUnNombre($texte, $matiere)) {
            Journal::noter('warn', 'direct', 'voix écartée : nombre absent de la matière');

            return null;
        }

        /* Un modèle qui se répète malgré la consigne, ça arrive : on le vérifie
           plutôt que de l'espérer. Comparaison sur les mots, pas sur la chaîne —
           deux phrases identiques à la ponctuation près sont la même phrase. */
        $empreinte = static fn (string $s): string => preg_replace('/[^a-z0-9 ]+/u', '', mb_strtolower($s)) ?? $s;
        foreach ($recentes as $ancienne) {
            if ($empreinte($ancienne) === $empreinte($texte)) {
                Journal::noter('warn', 'direct', 'voix écartée : formulation déjà passée');

                return null;
            }
        }

        array_unshift($recentes, $texte);
        self::poserEtat('voix_recentes', (string) json_encode(
            array_slice($recentes, 0, self::VOIX_MEMOIRE),
            JSON_UNESCAPED_UNICODE,
        ));

        // Le coût s'accumule : à une voix toutes les onze secondes, il n'est pas
        // anecdotique, et la note de quart doit pouvoir le dire.
        self::poserEtat('voix_jetons', (string) ((int) self::etat('voix_jetons') + $sortie['jetons']));

        return ['rang' => (int) ($dernier['rang'] ?? 0), 'texte' => $texte, 'jetons' => $sortie['jetons']];
    }

    /**
     * Les nombres écrits en lettres, ramenés à leur valeur.
     *
     * On compare des **valeurs**, pas des formes : « douze morts » et « 12
     * morts » sont la même affirmation, et le modèle passe légitimement de
     * l'une à l'autre en reformulant. Les traiter comme deux quantités
     * différentes faisait écarter des voix parfaitement fidèles.
     *
     * « un » et « une » n'y sont pas, et c'est délibéré : en français ce sont
     * des articles bien plus souvent que des quantités. Les compter faisait
     * écarter « **Un** incendie a parcouru 1 700 hectares », qui n'invente rien.
     */
    private const NOMBRES_ECRITS = [
        'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7,
        'huit' => 8, 'neuf' => 9, 'dix' => 10, 'onze' => 11, 'douze' => 12, 'treize' => 13,
        'quatorze' => 14, 'quinze' => 15, 'seize' => 16, 'vingt' => 20, 'trente' => 30,
        'quarante' => 40, 'cinquante' => 50, 'soixante' => 60, 'cent' => 100, 'cents' => 100,
        'mille' => 1000, 'million' => 1000000, 'millions' => 1000000,
        'milliard' => 1000000000, 'milliards' => 1000000000,
    ];

    /**
     * La voix avance-t-elle une quantité qui n'est pas dans la matière ?
     *
     * Chiffres et nombres écrits sont traités de la même façon : « 10 » et
     * « dix » sont la même affirmation, et le modèle passe librement de l'un à
     * l'autre. On ne compare donc pas les formes mais les **valeurs**.
     */
    private static function inventeUnNombre(string $voix, string $matiere): bool
    {
        $quantites = static function (string $texte): array {
            $texte = mb_strtolower($texte);
            $trouves = [];

            // Les chiffres, espaces fines et séparateurs retirés : « 1 700 »
            // et « 1700 » sont le même nombre.
            preg_match_all('/\d[\d  .,]*/u', $texte, $m);
            foreach ($m[0] as $n) {
                $net = preg_replace('/\D/u', '', $n);
                if ($net !== '' && $net !== '0') {
                    $trouves[] = $net;
                }
            }

            foreach (self::NOMBRES_ECRITS as $mot => $valeur) {
                if (preg_match('/\b' . $mot . '\b/u', $texte) === 1) {
                    $trouves[] = (string) $valeur;
                }
            }

            return array_unique($trouves);
        };

        $dansLaMatiere = $quantites($matiere);

        foreach ($quantites($voix) as $q) {
            if (!in_array($q, $dansLaMatiere, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{quand: int, fois: int}> */
    private static function vus(): array
    {
        $out = [];
        foreach (self::pdo()->query('SELECT groupe_id, quand, fois FROM direct_vu') as $r) {
            $out[(int) $r['groupe_id']] = ['quand' => (int) $r['quand'], 'fois' => (int) $r['fois']];
        }

        return $out;
    }

    /** @param list<int> $ids */
    private static function marquerVus(array $ids, int $maintenant): void
    {
        $st = self::pdo()->prepare(
            'INSERT INTO direct_vu (groupe_id, quand, fois) VALUES (:g, :q, 1)
             ON CONFLICT(groupe_id) DO UPDATE SET quand = :q, fois = fois + 1'
        );
        foreach ($ids as $id) {
            $st->execute(['g' => $id, 'q' => $maintenant]);
        }
    }

    /**
     * L'alerte préempte : c'est la seule chose qui interrompe le tour d'antenne.
     *
     * @param array<int, array{quand: int, fois: int}> $vus
     * @return array{nature: string, lancement: string, texte: string, pieces: list<Piece>, ids: list<int>}|null
     */
    private static function alerte(Base $base, array $vus, int $maintenant): ?array
    {
        foreach ($base->alertes($maintenant - 1800, Alerte::ALERTE, 6) as $g) {
            if (isset($vus[(int) $g['id']])) {
                continue;
            }

            return [
                'nature' => 'alerte',
                'texte'  => (string) $g['titre'],
                'pieces' => [Piece::evenement($g)],
                'ids'    => [(int) $g['id']],
                'lancement' => '',
            ];
        }

        return null;
    }

    /**
     * Le sujet le plus récent jamais passé.
     *
     * @param array<int, array{quand: int, fois: int}> $vus
     * @return array{nature: string, lancement: string, texte: string, pieces: list<Piece>, ids: list<int>}|null
     */
    private static function depeche(Base $base, array $vus): ?array
    {
        foreach ($base->arbre([], 40) as $g) {
            if (isset($vus[(int) $g['id']])) {
                continue;
            }

            return [
                'nature' => 'depeche',
                'texte'  => (string) $g['titre'],
                'pieces' => [Piece::evenement($g)],
                'ids'    => [(int) $g['id']],
                'lancement' => '',
            ];
        }

        return null;
    }

    /**
     * Le tour des titres, une rubrique à la fois.
     *
     * La rotation suit le rang du segment : local, national, thématique se
     * succèdent sans qu'on tienne un curseur de plus.
     *
     * @param array<int, array{quand: int, fois: int}> $vus
     * @return array{nature: string, lancement: string, texte: string, pieces: list<Piece>, ids: list<int>}|null
     */
    private static function bref(Base $base, array $vus, int $rang): ?array
    {
        $rubrique = self::ROTATION[$rang % count(self::ROTATION)];

        $pieces = [];
        $ids = [];
        foreach ($base->arbre(['rubrique' => $rubrique], 20) as $g) {
            if (isset($vus[(int) $g['id']])) {
                continue;
            }
            $pieces[] = Piece::evenement($g);
            $ids[] = (int) $g['id'];
            if (count($pieces) === 3) {
                break;
            }
        }

        if ($pieces === []) {
            return null;
        }

        return [
            'nature' => 'bref',
            'texte'  => Ecran::RUBRIQUES[$rubrique] ?? $rubrique,
            'pieces' => $pieces,
            'ids'    => $ids,
            'lancement' => '',
        ];
    }

    /**
     * La synthèse, périodique.
     *
     * Elle ne consomme pas les sujets : un point qui « brûlerait » ses sujets
     * empêcherait de les traiter ensuite un par un.
     *
     * @return array{nature: string, lancement: string, texte: string, pieces: list<Piece>, ids: list<int>}|null
     */
    private static function point(Base $base, int $rang): ?array
    {
        if ($rang === 0 || $rang % self::POINT_TOUS_LES !== 0) {
            return null;
        }

        $maintenant = time();
        $p = $base->passation($maintenant - 10800, $maintenant);
        $sujets = array_merge($p['eclate'] ?? [], $p['chaud'] ?? []);
        if ($sujets === []) {
            return null;
        }

        $pieces = [];
        foreach (array_slice($sujets, 0, 4) as $g) {
            $pieces[] = Piece::evenement($g);
        }

        return [
            'nature' => 'point',
            'texte'  => 'les trois dernières heures',
            'pieces' => $pieces,
            'ids'    => [],   // le point ne consomme rien
            'lancement' => '',
        ];
    }

    /**
     * La relance — ce qui empêche le direct de s'arrêter dans un creux.
     *
     * On reprend le sujet le plus repris parmi ceux déjà passés, à condition
     * qu'il ait eu le temps de vieillir. Le compteur `fois` le dit à l'antenne :
     * revenir sur un sujet est légitime, faire semblant qu'il est neuf ne l'est
     * pas.
     *
     * @return array{nature: string, lancement: string, texte: string, pieces: list<Piece>, ids: list<int>}
     */
    private static function relance(Base $base, int $maintenant): array
    {
        $st = self::pdo()->prepare(
            'SELECT groupe_id FROM direct_vu WHERE quand <= ? ORDER BY fois ASC, quand ASC LIMIT 1'
        );
        $st->execute([$maintenant - self::REPRISE_APRES]);
        $id = (int) $st->fetchColumn();

        if ($id > 0) {
            $groupes = $base->arbre(['groupe' => $id], 1);
            if ($groupes !== []) {
                return [
                    'nature' => 'relance',
                    'texte'  => (string) $groupes[0]['titre'],
                    'pieces' => [Piece::evenement($groupes[0])],
                    'ids'    => [$id],
                    'lancement' => '',
                ];
            }
        }

        /* Le dernier recours : la collecte elle-même. Il n'y a plus rien à
           dire de l'actualité, mais il y a toujours quelque chose de vrai à
           dire — combien de sources répondent, ce qui est tombé dans l'heure.
           Un direct qui annonce le calme reste un direct ; un direct muet est
           une panne. */
        $stats = $base->stats($maintenant);

        return [
            'nature' => 'relance',
            'texte'  => sprintf(
                'calme sur les %d sources — %d dépêches dans la dernière heure',
                (int) ($stats['sources']['total'] ?? 0),
                (int) $stats['h1'],
            ),
            'pieces' => [],
            'ids'    => [],
            'lancement' => '',
        ];
    }
}
