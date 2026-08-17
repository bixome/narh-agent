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
            // « rédactions », pas « sources » : depuis que la reprise compte par
            // maison, ce nombre est celui des salles qui ont titré, pas des flux.
            meta: $reprises > 1 ? '×' . $reprises . ' rédactions' : Util::age((int) $a['date_tri']),
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

        return new self(
            nature: self::EVENEMENT,
            id: (string) ($article > 0 ? $article : (int) $g['id']),
            quand: (int) ($g['tri'] ?? $g['dernier'] ?? 0),
            titre: (string) $g['titre'],
            acteur: $sources > 1 ? $sources . ' rédactions' : (string) ($g['source_nom'] ?? ''),
            poids: (int) $g['niveau'],
            meta: $sources > 1 ? '×' . $sources : Util::age((int) ($g['tri'] ?? 0)),
            marque: (string) ($g['statut'] ?? ''),
            attributs: array_filter([
                'groupe' => (string) (int) $g['id'],
                // Sans lui, « ouvrir » ne trouvait de lien que dans l'arbre.
                'lien'   => (string) ($g['article_lien'] ?? $g['depeches'][0]['lien'] ?? ''),
            ], static fn (string $v): bool => $v !== '' && $v !== '0'),
            pliable: $fils > 0,
            ouvert: $ouvert,
        );
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
        );
    }

    /** La même pièce, pliée autrement. */
    public function avecPliage(bool $pliable, bool $ouvert, bool $cache = false): self
    {
        return new self(
            $this->nature, $this->id, $this->quand, $this->titre, $this->acteur,
            $this->poids, $this->meta, $this->marque, $this->attributs,
            $this->profondeur, $pliable, $ouvert, $cache,
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
