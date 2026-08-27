<?php
declare(strict_types=1);

/**
 * La pièce — l'unité d'affichage de NARH, et la raison d'être de la refonte.
 *
 * Les deux projets d'origine avaient chacun leur vocabulaire visuel : une
 * dépêche chez l'un, un tour de conversation chez l'autre, avec leurs gabarits,
 * leurs glyphes et leurs colonnes. Les poser côte à côte dans une même page
 * aurait donné un portail à deux onglets — deux outils qui partagent une
 * feuille de style. Ce n'est pas ce qu'on veut : on veut un organisme.
 *
 * Tout ce que NARH montre est donc ramené ici à une seule forme :
 *
 *   une dépêche · un événement · un tour de modèle · un passage lu · un fait
 *
 * Ils ont tous un instant, un acteur, un titre, une intensité et une marque.
 * `Vue::ligne()` en rend un, quelle que soit sa nature — et c'est ce qui fait
 * qu'une conversation s'insérera dans le fil de veille sans un gabarit de plus,
 * quand P2 arrivera.
 *
 * Deux axes, jamais confondus :
 *
 * - la **nature** donne le glyphe : ce que la chose *est* ;
 * - le **poids** donne le ton : à quel point elle compte.
 *
 * Un objet immuable, sans dépendance : il ne sait ni lire une base ni écrire du
 * HTML. Les fabriques traduisent une ligne de base en pièce ; `Vue` traduit une
 * pièce en balisage. Personne ne fait les deux.
 */
final class Piece
{
    public const DEPECHE   = 'depeche';
    public const EVENEMENT = 'evenement';
    public const FAIT      = 'fait';
    public const FIL       = 'fil';      // un fil de conversation, dans la liste
    public const TOUR      = 'tour';     // un tour, dans la conversation elle-même
    public const PASSAGE   = 'passage';  // P5 — un extrait d'article lu

    /** L'échelle d'intensité, commune à la veille et à l'agent. */
    public const CALME   = 0;
    public const NOTABLE = 1;
    public const FORT    = 2;
    public const VIF     = 3;

    /**
     * @param string                $nature     l'une des constantes ci-dessus
     * @param string                $id         identifiant dans sa nature
     * @param int                   $quand      l'instant qui la situe
     * @param string                $titre      ce qu'on lit
     * @param string                $acteur     qui parle : une rédaction, le modèle, l'écran
     * @param int                   $poids      0 à 3
     * @param string                $meta       la colonne de droite : un âge, un compte
     * @param string                $marque     suivi | traite | ecarte | ''
     * @param array<string, string> $attributs  data-* portés par la ligne
     * @param int                   $profondeur 0 pour une tête, 1 pour ce qu'elle contient
     */
    public function __construct(
        public readonly string $nature,
        public readonly string $id,
        public readonly int $quand,
        public readonly string $titre,
        public readonly string $acteur = '',
        public readonly int $poids = self::CALME,
        public readonly string $meta = '',
        public readonly string $marque = '',
        public readonly array $attributs = [],
        public readonly int $profondeur = 0,
        public readonly bool $pliable = false,
        public readonly bool $ouvert = false,
        public readonly bool $cache = false,
        /**
         * Ce qui **décrit** la pièce, en toutes lettres — une ligne par sujet
         * de description : d'où elle vient et comment elle s'est propagée ;
         * pourquoi elle est notée ainsi et ce qu'on en a fait.
         *
         * Vide par défaut, et c'est ce qui évite un mode : un tour de
         * conversation ou une ligne de journal n'a rien à décrire, donc n'en
         * porte pas, et la même méthode de rendu sert les deux sans qu'un
         * drapeau circule. Seul un événement en reçoit, et seulement quand
         * l'appelant a demandé la description à `Base::arbre()`.
         *
         * @var list<string>
         */
        public readonly array $details = [],
    ) {
    }

    /**
     * La même pièce, avec une autre mesure.
     *
     * Les propriétés sont en lecture seule : une pièce ne se modifie pas, elle
     * se refait. C'est ce qui permet de la passer d'un panneau à l'autre sans
     * craindre qu'un panneau l'ait changée pour les autres.
     */
    public function avecMeta(string $meta): self
    {
        return new self(
            $this->nature, $this->id, $this->quand, $this->titre, $this->acteur,
            $this->poids, $meta, $this->marque, $this->attributs,
            $this->profondeur, $this->pliable, $this->ouvert, $this->cache,
            $this->details,
        );
    }

    /**
     * Une dépêche.
     *
     * @param array<string, mixed> $a ligne rendue par Base::flux()
     */
    public static function depeche(array $a, int $profondeur = 0, ?string $parent = null): self
    {
        $reprises = (int) ($a['reprises'] ?? 1);

        return new self(
            nature: self::DEPECHE,
            id: (string) (int) $a['id'],
            quand: (int) $a['date_tri'],
            titre: (string) $a['titre'],
            acteur: (string) ($a['source_nom'] ?? ''),
            poids: (int) $a['niveau'],
            /* L'acteur dit **qui**, le méta dit **quand** — sans exception,
               sinon la même colonne change de sujet d'une ligne à l'autre.

               Le compte de reprises a été retiré d'ici : il porte sur le
               groupe, jamais sur cette dépêche-là. Affiché sur chacune, il
               donnait « Ouest-France … ×3 rédactions » puis « franceinfo …
               ×3 rédactions » — la même reprise recomptée à chaque ligne,
               comme si Ouest-France s'était repris trois fois lui-même. Le
               nombre reste sur l'événement, où il est vrai, et la place
               revient au titre. */
            meta: Util::age((int) $a['date_tri']),
            marque: (string) ($a['statut'] ?? ''),
            attributs: array_filter([
                'lien'   => (string) ($a['lien'] ?? ''),
                'groupe' => (string) (int) ($a['groupe_id'] ?? 0),
                'parent' => $parent ?? '',
            ], static fn (string $v): bool => $v !== '' && $v !== '0'),
            profondeur: $profondeur,
        );
    }

    /**
     * Un événement : le même fait, vu par plusieurs rédactions.
     *
     * @param array<string, mixed> $g ligne rendue par Base::arbre()
     */
    public static function evenement(array $g, bool $ouvert = false): self
    {
        $sources = (int) ($g['sources'] ?? 1);
        $fils = (int) ($g['fils'] ?? 0);

        /* L'identifiant porté par la ligne est celui d'une **dépêche**, jamais
           celui du groupe.

           C'est ce que l'inspecteur ira chercher (`api/apercu.php?type=depeche`),
           et un groupe n'a pas de texte à montrer. Le groupe reste dans
           `data-groupe`, où le marquage et le repli le retrouvent.

           Le piège, et il ne se voit pas : les deux numérotations se recouvrent
           — 7 673 groupes pour 10 124 articles. Une ligne d'événement portant
           l'identifiant de son groupe ouvrait donc presque toujours *un*
           article, simplement pas le sien. Aucune erreur, aucun vide : juste le
           mauvais article. `evenementEtReprises()` corrigeait déjà le cas de
           l'arbre après coup ; les alertes, l'en-tête et le direct, qui
           appellent cette fabrique directement, ne l'avaient pas.

           Trois requêtes différentes alimentent cette fabrique et ne nomment pas
           la dépêche de tête pareil — d'où les trois essais avant le repli. */
        $article = (int) ($g['article_id'] ?? 0)
            ?: (int) ($g['depeches'][0]['id'] ?? 0)
            ?: (int) ($g['dernier_article'] ?? 0);

        /* L'instant, résolu **une fois** pour l'heure et pour l'âge.

           Les deux le lisaient chacun de leur côté, et l'un des deux avait
           oublié le repli : `arbre()` ne nomme pas la colonne `tri` mais
           `dernier`, si bien que l'âge tombait sur zéro et s'affichait
           « 01/01 01:00 » — une date de 1970 au milieu du Newsdesk. Deux
           lectures de la même chose finissent toujours par diverger. */
        $quand = (int) ($g['tri'] ?? $g['dernier'] ?? 0);

        return new self(
            nature: self::EVENEMENT,
            id: (string) ($article > 0 ? $article : (int) $g['id']),
            quand: $quand,
            titre: (string) $g['titre'],
            acteur: $sources > 1 ? $sources . ' rédactions' : (string) ($g['source_nom'] ?? ''),
            poids: (int) $g['niveau'],
            /* Le méta portait « ×3 » pendant que l'acteur portait déjà
               « 3 rédactions » : deux fois le même nombre sur une ligne, aux
               deux bouts. Ce qu'on perdait n'était pas de la place mais une
               information — l'âge, que toutes les autres lignes affichent là.
               Une colonne qui dit l'heure ici et un décompte ailleurs oblige à
               relire son intitulé à chaque ligne. */
            /* « auto · 3 h » plutôt que « 3 h » quand le marquage vient d'une
               conduite et non de l'utilisateur.

               L'onglet annonçait « Suivis 70 » comme si les soixante-dix
               décisions étaient les siennes : mesuré, 49 des 98 marquages de la
               base viennent de la seule conduite `suivre-les-alertes`. Montrer
               le travail de l'agent comme celui de l'humain est le seul endroit
               de NARH où l'écran dit quelque chose de faux.

               C'est une **déduction**, et sa limite doit être connue : on lit
               « une conduite a touché ce groupe » et « ce groupe est marqué »,
               pas « c'est la conduite qui l'a marqué ». Un sujet marqué par une
               conduite puis re-marqué à la main portera donc « auto » à tort —
               `conduite_vu` ne s'efface pas. La réponse exacte serait une
               colonne `statut_par` ; elle coûte une migration, et la déduction
               couvre déjà le cas qui trompe vraiment, celui du desk qu'on
               découvre au réveil. */
            /* « auto » n'est ici que si la carte ne le dit pas déjà en toutes
               lettres plus bas. Les deux ensemble, c'était la même information
               à deux endroits d'une même entrée — le défaut exact que le
               commentaire de `depeche()` décrit pour « ×3 rédactions », et qui
               coûte de la place dans la colonne la plus étroite de l'écran.
               Sans description demandée, la mention reste : c'est alors le seul
               endroit qui distingue ta décision de celle d'une conduite. */
            meta: (!isset($g['ouvreur']) && ($g['statut'] ?? '') !== '' && ($g['conduites'] ?? []) !== []
                    ? 'auto · ' : '')
                . Util::age($quand),
            marque: (string) ($g['statut'] ?? ''),
            attributs: array_filter([
                'groupe' => (string) (int) $g['id'],
                // Sans lui, « ouvrir » ne trouvait de lien que dans l'arbre.
                'lien'   => (string) ($g['article_lien'] ?? $g['depeches'][0]['lien'] ?? ''),
                /* Portés par la ligne sans y être écrits : la colonne fait
                   396 px et le titre n'en tient déjà que 34 %. L'inspecteur, lui,
                   a la place de dire « passé à l'antenne le 23/08 à 15:32 » —
                   et « jamais passé » sur un sujet à treize maisons est
                   exactement ce qu'un desk doit voir. */
                'antenne'   => (string) (int) ($g['antenne'] ?? 0),
                'conduites' => implode(' ', $g['conduites'] ?? []),
            ], static fn (string $v): bool => $v !== '' && $v !== '0'),
            pliable: $fils > 0,
            ouvert: $ouvert,
            details: self::description($g),
        );
    }

    /**
     * Ce qu'un événement donne à **lire** — en français, pas en glyphes.
     *
     * Deux lignes, et l'ordre est celui des questions qu'on se pose :
     *
     * 1. **D'où ça vient et jusqu'où c'est allé.** Qui a sorti le sujet, en
     *    combien de temps une autre rédaction a suivi, combien corroborent,
     *    combien d'articles, depuis combien de temps ça dure. C'est la règle
     *    journalistique de la source et la mesure de propagation, dites
     *    ensemble parce qu'elles ne se lisent pas l'une sans l'autre : « repris
     *    en 11 minutes » ne veut rien dire sans « par 12 autres ».
     * 2. **Pourquoi c'est noté ainsi, et ce qu'on en a fait.** `motifs` porte
     *    les mots du lexique qui ont fait le score — 9 235 sujets en ont un et
     *    il n'a jamais été affiché nulle part, si bien qu'un niveau « alerte »
     *    arrivait sans sa raison. C'est le morceau d'information sémantique qui
     *    manquait : la mesure était là, la justification restait en base.
     *
     * Vide si l'appelant n'a pas demandé la description : voir `$details`.
     *
     * @param  array<string, mixed> $g
     * @return list<string>
     */
    private static function description(array $g): array
    {
        if (!isset($g['ouvreur'])) {
            return [];
        }

        $lignes = [];
        $sources = (int) ($g['sources'] ?? 0);

        $provenance = [];
        if (($g['ouvreur'] ?? '') !== '') {
            /* Le rang est dit quand il n'est pas une rédaction : « ouvert par
               gnews » laisse croire à une salle de rédaction alors que c'est un
               agrégateur qui recopie. La nuance change la confiance qu'on
               accorde à la ligne, donc elle s'écrit. */
            $rang = (string) ($g['ouvreur_rang'] ?? '');
            $dit = ['agregateur' => ' (agrégateur)', 'social' => ' (web social)', 'continu' => ' (fil continu)'];
            /* Le **nom du flux**, pas la maison : `maison` est une clé de
               regroupement (`bfm`, `20minutes`) et se lisait comme telle à
               l'écran. `nom` est écrit pour être lu — « BFM Justice » — et il
               est du reste plus juste : c'est ce flux-là qui a sorti le sujet,
               pas la rédaction en bloc. La maison reste ce qui compare les
               reprises, où c'est elle qui a un sens. */
            $provenance[] = 'ouvert par ' . ($g['ouvreur_nom'] ?: $g['ouvreur']) . ($dit[$rang] ?? '');
        }

        /* « repris », jamais « suivi ». La carte porte les deux notions à la
           fois — la propagation entre rédactions et l'état de desk — et « suivi
           par 2 autres · suivi par une conduite » faisait porter au même mot
           deux sens sur une même entrée. « Reprise » est de surcroît le terme
           du métier, et celui qu'emploie déjà `motifs` (« reprise ×3 ») : trois
           écritures pour une seule idée, c'en était deux de trop. */
        $reprise = (int) ($g['reprise'] ?? 0);
        if ($sources > 1) {
            $provenance[] = $reprise > 0
                ? 'repris par ' . ($sources - 1) . ' autres en ' . Util::intervalle($reprise)
                : 'repris par ' . ($sources - 1) . ' autres';
        } else {
            // Une information, pas un manque : personne n'a repris, et c'est
            // précisément ce qui distingue 95 % du flux du reste.
            $provenance[] = 'aucune reprise';
        }

        $articles = (int) ($g['articles'] ?? 0);
        if ($articles > 1) {
            $provenance[] = $articles . ' articles';
        }

        $vie = (int) ($g['dernier'] ?? 0) - (int) ($g['premier'] ?? 0);
        if ($vie >= 3600) {
            $provenance[] = Util::intervalle($vie) . ' de vie';
        }

        if ($provenance !== []) {
            $lignes[] = implode(' · ', $provenance);
        }

        $sens = [];
        $motifs = trim((string) ($g['motifs'] ?? ''));
        if ($motifs !== '') {
            $sens[] = str_replace(',', ', ', $motifs);
        }
        /* L'état est **écrit**, pas seulement teinté. Les trois marquages ne
           sont plus trois onglets mais une seule liste : la couleur seule
           laisserait deviner lequel des trois, et `--xo-tint` n'a jamais été
           fait pour porter une information qu'on ne peut pas lire autrement. */
        $etat = ['suivi' => 'suivi', 'traite' => 'traité', 'ecarte' => 'écarté'];
        if (($dit = $etat[(string) ($g['statut'] ?? '')] ?? '') !== '') {
            $sens[] = ($g['conduites'] ?? []) !== []
                ? $dit . ' par une conduite'
                : $dit . ' à la main';
        }
        if (isset($g['antenne']) && (int) $g['antenne'] === 0) {
            $sens[] = 'jamais passé à l’antenne';
        }

        if ($sens !== []) {
            $lignes[] = implode(' · ', $sens);
        }

        return $lignes;
    }

    /**
     * Un événement et ses reprises, à plat.
     *
     * L'arbre est une liste plate à guides, pas des boîtes imbriquées : la
     * navigation reste continue, et replier un événement revient à cacher ses
     * enfants — XOSHUI saute déjà les `[hidden]`.
     *
     * La tête porte l'identifiant de sa **première dépêche** et non celui du
     * groupe : c'est ce que l'inspecteur ira chercher, et un groupe n'a pas de
     * texte à montrer. Le groupe, lui, reste dans `data-groupe`, où le marquage
     * et le repli le retrouvent.
     *
     * @param array<string, mixed> $g ligne rendue par Base::arbre()
     * @return list<self>
     */
    public static function evenementEtReprises(array $g): array
    {
        $depeches = $g['depeches'] ?? [];
        $groupe = (string) (int) $g['id'];

        // Une dépêche seule n'a rien à déplier : son titre est déjà celui de
        // l'événement. Le chevron ne s'affiche que s'il y a quelque chose dessous.
        $pliable = count($depeches) > 1;
        // Ce qui est en alerte s'ouvre de soi-même : c'est ce qu'on vient lire.
        $ouvert = $pliable && (int) $g['niveau'] >= self::FORT;

        $tete = self::evenement($g, $ouvert);
        if ($pliable) {
            $tete = $tete->avecPliage(true, $ouvert);
        }
        if (($premier = $depeches[0] ?? null) !== null) {
            $tete = $tete->avecId((string) (int) $premier['id']);
        }

        $liste = [$tete];
        if (!$pliable) {
            return $liste;
        }

        foreach ($depeches as $a) {
            $liste[] = self::depeche($a, 1, $groupe)->avecPliage(false, false, !$ouvert);
        }

        return $liste;
    }

    /** La même pièce, sous un autre identifiant. */
    public function avecId(string $id): self
    {
        return new self(
            $this->nature, $id, $this->quand, $this->titre, $this->acteur,
            $this->poids, $this->meta, $this->marque, $this->attributs,
            $this->profondeur, $this->pliable, $this->ouvert, $this->cache,
            $this->details,
        );
    }

    /** La même pièce, pliée autrement. */
    public function avecPliage(bool $pliable, bool $ouvert, bool $cache = false): self
    {
        return new self(
            $this->nature, $this->id, $this->quand, $this->titre, $this->acteur,
            $this->poids, $this->meta, $this->marque, $this->attributs,
            $this->profondeur, $pliable, $ouvert, $cache,
            $this->details,
        );
    }

    /**
     * Un fil, dans la liste des conversations.
     *
     * @param array{id: int, titre: string, maj: int, tours: int, dernier: ?string} $f
     */
    public static function fil(array $f, bool $courant = false): self
    {
        $titre = trim((string) $f['titre']) !== '' ? (string) $f['titre'] : 'Sans titre';

        return new self(
            nature: self::FIL,
            id: (string) (int) $f['id'],
            quand: (int) $f['maj'],
            titre: $titre,
            poids: self::CALME,
            meta: (int) $f['tours'] . ' tour' . ((int) $f['tours'] > 1 ? 's' : ''),
            marque: $courant ? 'suivi' : '',
        );
    }

    /**
     * Un fait de la chronologie commune : un cycle, un geste, un tour de modèle.
     *
     * C'est la pièce qui porte la règle 7 — la collecte et l'agent y prennent la
     * même forme, et c'est pour cela qu'ils se lisent dans le même ordre.
     *
     * @param array{quand: int, niveau: string, source: string, message: string, duree: ?int} $e
     */
    public static function fait(array $e): self
    {
        return new self(
            nature: self::FAIT,
            id: (string) $e['quand'],
            quand: (int) $e['quand'],
            titre: (string) $e['message'],
            acteur: (string) $e['source'],
            poids: match ($e['niveau']) {
                'error' => self::VIF,
                'warn'  => self::FORT,
                'ok'    => self::NOTABLE,
                default => self::CALME,
            },
            meta: $e['duree'] !== null ? Util::duree((int) $e['duree']) : '',
        );
    }

    /**
     * Un passage du corpus — le texte retenu, pas le titre.
     *
     * C'est le troisième état de la pièce : *reçu* pour une dépêche, *demandé*
     * pour un tour, **retenu** pour ceci. Le titre de la pièce est l'extrait
     * lui-même : dans une liste, c'est ce qu'on vient lire — le titre de
     * l'article, lui, passe en méta, comme la provenance d'une citation.
     *
     * Le poids reste calme : un passage ne porte pas d'urgence propre, c'est le
     * texte d'un article déjà noté ailleurs. Lui en donner une ferait croire à
     * une alerte là où il n'y a qu'une correspondance de mots.
     *
     * @param array<string, mixed> $p
     */
    public static function passage(array $p): self
    {
        $date = (string) ($p['date'] ?? '');

        return new self(
            nature: self::PASSAGE,
            id: (string) ($p['lien'] ?? ''),
            quand: $date !== '' ? (int) strtotime($date) : 0,
            titre: (string) ($p['texte'] ?? ''),
            acteur: (string) ($p['source'] ?? ''),
            poids: self::CALME,
            meta: Util::tronquer((string) ($p['titre'] ?? ''), 40),
            attributs: ['lien' => (string) ($p['lien'] ?? '')],
        );
    }
}
