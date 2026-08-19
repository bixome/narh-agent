<?php
declare(strict_types=1);

/**
 * Le rendu — une grammaire, pas deux.
 *
 * Tout ce que NARH affiche passe par `ligne()` : une dépêche, un événement, un
 * fait du journal, et demain un tour de conversation ou un passage lu. Les deux
 * projets d'origine avaient chacun le sien ; les garder aurait donné deux vues
 * juxtaposées, et un écran où l'œil doit réapprendre à lire en changeant de
 * colonne. Une seule méthode, une seule ligne, cinq colonnes toujours aux mêmes
 * places :
 *
 *   guide · issue · instant · acteur · titre · mesure
 *
 * Épuré ne veut pas dire pauvre : chaque colonne porte une information que les
 * autres ne portent pas, et rien n'est écrit deux fois. Le marquage de desk est
 * une **teinte de fond**, pas un glyphe de plus ; l'âge est une **pâleur**, pas
 * une colonne de plus. C'est ce qui permet de tenir cinq informations sur une
 * ligne sans qu'elle devienne un tableau.
 *
 * Un seul endroit produit ce balisage : l'écran au chargement, l'API à chaque
 * sondage. Ne pas le réécrire en JavaScript (CLAUDE.md, règle 2).
 *
 * Le balisage est celui de XOSHUI, sans une classe de plus.
 */
final class Vue
{
    /** Le ton dit l'intensité — commun à la veille et à l'agent. */
    private const TONS = [
        Piece::CALME   => 'faint',
        Piece::NOTABLE => 'info',
        Piece::FORT    => 'warning',
        Piece::VIF     => 'danger',
    ];

    /**
     * Le glyphe dit la nature, jamais l'intensité — le ton s'en charge déjà.
     *
     * Tous pris dans le pack vérifié de XOSHUI (icons.php) : un caractère absent
     * de JetBrains Mono serait rendu par une police de secours, à une autre
     * chasse, et la ligne sortirait de la grille.
     */
    private const GLYPHES = [
        Piece::DEPECHE   => ['·', '○', '◆', '●'],
        Piece::EVENEMENT => ['·', '○', '◆', '●'],
        Piece::FAIT      => ['·', '●', '▲', '✗'],
        Piece::FIL       => ['›', '›', '›', '›'],
        Piece::TOUR      => ['›', '›', '›', '›'],
        Piece::PASSAGE   => ['¶', '¶', '¶', '¶'],
    ];

    /** Le marquage de desk : un fond, pas un glyphe. */
    private const TEINTES = [
        'suivi'  => ' xo-tint--accent',
        'traite' => ' xo-tint--success',
        'ecarte' => ' xo-tint--faint',
    ];

    /** Le glyphe d'une dépêche, pour les écrans qui en citent une hors liste. */
    public static function glyphe(int $poids): string
    {
        return self::GLYPHES[Piece::DEPECHE][$poids] ?? '·';
    }

    /* ---- La ligne --------------------------------------------------------- */

    /**
     * Une pièce, quelle que soit sa nature.
     *
     * `--xo-i` échelonne l'apparition : les lignes d'un même lot tombent l'une
     * après l'autre, comme une sortie de terminal. Au-delà de douze, l'attente
     * deviendrait de la lenteur — l'index est plafonné.
     */
    public static function ligne(Piece $p, ?int $rang = null): string
    {
        $ton = self::TONS[$p->poids] ?? 'faint';
        $glyphe = self::GLYPHES[$p->nature][$p->poids] ?? '·';

        $classes = 'xo-list__item' . (self::TEINTES[$p->marque] ?? '');
        $style = '';
        if ($rang !== null) {
            $classes .= ' xo-appear';
            $style .= '--xo-i: ' . min($rang, 12) . ';';
        }
        if ($p->profondeur > 0) {
            $style .= '--xo-depth: ' . $p->profondeur . ';';
        }

        // data-tri porte l'instant : c'est ce qui permet au navigateur d'insérer
        // une pièce à sa place, et pas seulement en tête. Une source qui
        // rattrape son retard livre des articles vieux de trois jours — ils ne
        // doivent pas coiffer l'actualité.
        $data = ' data-value="' . e($p->id) . '"'
            // La nature voyage avec la ligne : le navigateur doit savoir si ce
            // qu'on vient de choisir est une dépêche à inspecter ou un fil à
            // rouvrir, sans le deviner à l'endroit où elle se trouve.
            . ' data-nature="' . e($p->nature) . '"'
            . ' data-niveau="' . $p->poids . '"'
            . ' data-tri="' . $p->quand . '"'
            . ' data-statut="' . e($p->marque) . '"'
            . ' data-libelle="' . e(Util::tronquer($p->titre, 70)) . '"';

        foreach ($p->attributs as $nom => $valeur) {
            $data .= ' data-' . $nom . '="' . e($valeur) . '"';
        }
        if ($p->pliable) {
            $data .= ' data-pliable' . ($p->ouvert ? ' data-ouvert' : '');
        }

        $fade = self::fraicheur($p->quand);
        $classeIcone = 'xo-list__icon xo-' . $ton;
        $classeTitre = $p->poids >= Piece::FORT ? ' class="xo-bold"' : '';

        return '<li class="' . $classes . '" role="option" aria-selected="false"'
            . $data . ($style !== '' ? ' style="' . $style . '"' : '') . ($p->cache ? ' hidden' : '') . '>'

            // Le guide n'existe que dans un arbre : ailleurs il ne réserve rien,
            // et les colonnes suivantes restent alignées d'une liste à l'autre.
            . ($p->pliable
                ? '<span class="xo-list__guide" aria-hidden="true">' . ($p->ouvert ? '▾ ' : '▸ ') . '</span>'
                : '')

            . '<span class="' . $classeIcone . '" aria-hidden="true">' . $glyphe . '</span>'

            /* L'instant situe une dépêche ou un fait — c'est leur première
               information. Un fil de conversation, lui, se reconnaît à son
               titre : dans une barre latérale de 24 caractères, lui donner cinq
               colonnes d'heure ne laissait plus rien à lire. */
            . ($p->nature !== Piece::FIL
                ? '<span class="xo-faint xo-nowrap xo-fade" style="flex: none;' . $fade . '">'
                    . e(date('H:i', $p->quand)) . '</span>'
                : '')

            . ($p->acteur !== ''
                ? '<span class="xo-muted xo-nowrap xo-fade" style="flex: none; width: 13ch;' . $fade . '">'
                    . e(Util::tronquer($p->acteur, 13)) . '</span>'
                : '')

            // data-titre : le navigateur remet le gras quand une reprise fait
            // monter le niveau. Repérer ce span par son rang le condamnerait à
            // bouger au premier ajout dans la ligne.
            . '<span data-titre' . $classeTitre . '>' . e($p->titre) . '</span>'

            . ($p->meta !== '' ? '<span class="xo-list__meta">' . e($p->meta) . '</span>' : '')
            . '</li>';
    }

    /**
     * Une suite de pièces.
     *
     * @param list<Piece> $pieces
     */
    public static function lignes(array $pieces, bool $echelonner = false): string
    {
        $html = '';
        $rang = 0;
        foreach ($pieces as $p) {
            $html .= self::ligne($p, $echelonner ? $rang++ : null);
        }

        return $html;
    }

    /**
     * Un événement et ses reprises, prêts à être posés d'un bloc.
     *
     * Le navigateur repose ce bloc entier quand l'événement bouge : c'est le
     * serveur qui décide de ce qu'il contient, jamais lui.
     *
     * @param array<string, mixed> $g
     */
    public static function noeud(array $g): string
    {
        return self::lignes(Piece::evenementEtReprises($g));
    }

    /**
     * Le fil en arbre.
     *
     * Un `<ul>` plat à guides, pas des `<details>` imbriqués : `data-xo-list` ne
     * navigue que dans une liste, et des boîtes repliables couperaient le
     * clavier en autant d'îlots. XOSHUI saute déjà les `[hidden]` — replier un
     * événement retire ses reprises du parcours sans une ligne de JavaScript.
     *
     * @param list<array<string, mixed>> $groupes
     */
    public static function arbre(array $groupes): string
    {
        $html = '';
        foreach ($groupes as $g) {
            $html .= self::noeud($g);
        }

        return $html;
    }

    /* ---- Les panneaux ------------------------------------------------------ */

    /**
     * Les alertes — les mêmes lignes que le fil, dans une colonne plus étroite.
     *
     * Rien de spécifique ici, et c'est voulu : un panneau qui invente sa propre
     * ligne oblige à la maintenir, et elle finit par ne plus ressembler au fil
     * qu'elle commente.
     *
     * @param list<array<string, mixed>> $groupes
     * @param list<int>                  $relance identifiants à relancer
     */
    public static function alertes(array $groupes, array $relance = []): string
    {
        if ($groupes === []) {
            return self::vide('rien', 'Aucune alerte sur les six dernières heures.');
        }

        $pieces = [];
        foreach ($groupes as $g) {
            $p = Piece::evenement($g);
            // « À relancer » : l'événement a fait alerte puis s'est tu. Une
            // absence ne s'affiche pas toute seule — la mesure le dit.
            if (in_array((int) $g['id'], $relance, true)) {
                $p = $p->avecMeta('à relancer');
            }
            $pieces[] = $p;
        }

        // Pas de data-xo-menu ici : le menu contextuel ne doit avoir qu'un seul
        // propriétaire. XOSHUI câble un clic sur #menu-narh par liste qui le
        // revendique — en donner un second déclenche deux commandes pour un
        // seul clic, chacune avec sa propre cible. Le fil seul le porte ; les
        // alertes restent sélectionnables (l'inspecteur suit), sans clic droit.
        return '<ul class="xo-list" data-xo-list role="listbox"'
            . ' aria-label="Alertes">' . self::lignes($pieces) . '</ul>';
    }

    /**
     * Une liste d'événements, sans cadre ni compteur.
     *
     * Pour les colonnes étroites — l'en-tête du Newsdesk — où `alertes()`
     * mettrait son état vide et son plafond de six heures alors que le panneau
     * les dit déjà.
     *
     * @param list<array<string, mixed>> $groupes
     */
    public static function lignesEvenements(array $groupes): string
    {
        $pieces = array_map(static fn (array $g): Piece => Piece::evenement($g), $groupes);

        return '<ul class="xo-list" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
            . ' aria-label="Événements">' . self::lignes($pieces) . '</ul>';
    }

    /**
     * Une liste de dépêches, sans cadre.
     *
     * Le pendant de `lignesEvenements()` pour ce qui sort d'une recherche : on
     * cherche des dépêches, on survole des événements, et l'arbre n'a pas de
     * sens sur un résultat classé par pertinence.
     *
     * @param list<array<string, mixed>> $depeches
     */
    public static function lignesDepeches(array $depeches): string
    {
        if ($depeches === []) {
            return '<p class="xo-muted">Aucune dépêche ne correspond.</p>';
        }

        $pieces = array_map(static fn (array $a): Piece => Piece::depeche($a), $depeches);

        return '<ul class="xo-list" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
            . ' aria-label="Dépêches">' . self::lignes($pieces) . '</ul>';
    }

    /**
     * La chronologie commune — la collecte et l'agent dans le même ordre.
     *
     * C'est la vue qui porte la règle 7, et elle emploie la même ligne que le
     * fil : un cycle de collecte et une dépêche se lisent de la même façon,
     * parce que ce sont deux choses qui sont arrivées.
     *
     * @param list<array{quand: int, niveau: string, source: string, message: string, duree: ?int}> $entrees
     */
    public static function activite(array $entrees): string
    {
        if ($entrees === []) {
            return self::vide('calme', "Rien n'a encore été fait.");
        }

        $pieces = array_map(static fn (array $e): Piece => Piece::fait($e), $entrees);

        return '<ul class="xo-list" data-xo-list role="listbox" aria-label="Journal">'
            . self::lignes($pieces) . '</ul>';
    }

    /* ---- Le chat ------------------------------------------------------------
       Structure de chat, pas peau de chat : `xo-timeline` porte les tours,
       la même grammaire que « qui d'autre en parle » dans l'inspecteur, parce
       qu'une conversation est aussi une suite d'événements datés. Aucune classe
       « bulle » : distinguer qui parle passe par le glyphe du marqueur et par
       l'acteur affiché (CLAUDE.md, § Le chat). */

    /**
     * Les fils, dans le panneau de gauche du chat.
     *
     * @param list<array{id: int, titre: string, maj: int, tours: int, dernier: ?string}> $fils
     */
    public static function fils(array $fils, int $courant): string
    {
        if ($fils === []) {
            return self::vide('vierge', "Aucune conversation. Écrire un message en ouvre une.");
        }

        $pieces = array_map(
            static fn (array $f): Piece => Piece::fil($f, (int) $f['id'] === $courant),
            $fils,
        );

        return '<ul class="xo-list" data-xo-list role="listbox" aria-label="Fils de conversation">'
            . self::lignes($pieces) . '</ul>';
    }

    /**
     * Un tour de conversation.
     *
     * Trois acteurs possibles : l'utilisateur, NARH, et un outil consulté à la
     * main. Chacun son glyphe ; aucun fond de couleur par rôle, ce qui
     * distinguerait le locuteur d'une façon que XOSHUI ne connaît pas.
     */
    public static function tour(array $t): string
    {
        /* Le ton distingue les natures de tour.

           Le glyphe seul ne suffisait pas : dans un flux où se mêlent la
           conversation, les tuiles convoquées et les pièces versées par la
           veille, l'œil doit trier avant de lire. La couleur porte ici la
           **nature de l'énonciateur**, jamais un état — le marquage, la
           fraîcheur et la confirmation gardent les leurs (CLAUDE.md, § La
           conversation). */
        $role = (string) $t['role'];
        [$glyphe, $acteur, $ton] = match ($role) {
            'user'      => ['▸', 'vous', 'muted'],
            'assistant' => ['›', 'NARH', 'accent'],
            'quart'     => ['■', "fin d'antenne", 'warning'],
            'tuile'     => ['⊞', 'écran', 'special'],
            default     => ['⚙', 'veille', 'info'],
        };

        $classeMarqueur = 'xo-timeline__marker xo-' . $ton;
        $classeActeur = 'xo-bold xo-' . $ton;

        /* Une note de quart se relit telle qu'elle a été écrite : son bilan est
           stocké dans le tour, pas recalculé. C'est le seul contenu de NARH qui
           soit une photo — parce que c'est le seul qui parle d'un moment révolu
           plutôt que de l'état présent. */
        $quart = $role === 'quart' && is_array($t['bilan'] ?? null)
            ? self::noteDeQuart($t['bilan'])
            : '';

        $outils = '';
        foreach ($t['etapes'] ?? [] as $e) {
            $outils .= '<div class="xo-faint" style="margin-top: 4px">'
                . '⚙ ' . e((string) ($e['outil'] ?? '?'))
                . (($e['ok'] ?? true) ? '' : ' — échec')
                . '</div>';
        }

        $jetons = (int) ($t['jetons'] ?? 0) > 0
            ? '<span class="xo-timeline__time xo-faint">' . (int) $t['jetons'] . ' jetons</span>'
            : '';

        // Un tour qui ne porte qu'une tuile n'a pas de texte à afficher : le
        // résultat *est* le message.
        $texte = trim((string) $t['content']) !== ''
            ? '<div>' . nl2br(e((string) $t['content']), false) . '</div>'
            : '';

        /* `data-quand` porte l'instant : le flux mêle des tours et des segments
           d'antenne, et sans lui le navigateur ne saurait pas où insérer les
           seconds parmi les premiers — ils remontaient tous en bloc. */
        return '<li class="xo-timeline__item" data-quand="' . (int) ($t['quand'] ?? 0) . '"'
            . ' data-role="' . e($role) . '">'
            . '<span class="' . $classeMarqueur . '" aria-hidden="true">' . $glyphe . '</span>'
            . '<div class="xo-timeline__body">'
            . '<div><span class="' . $classeActeur . '">' . e($acteur) . '</span> '
            . '<span class="xo-timeline__time">' . e((string) $t['heure']) . '</span> ' . $jetons . '</div>'
            . $texte
            . $quart
            . $outils
            . self::tuiles($t['tuiles'] ?? [])
            . self::sources($t['etapes'] ?? [])
            . '</div>'
            . '</li>';
    }

    /**
     * Les tuiles d'un tour.
     *
     * Elles se fractionnent : deux tuiles « demi » se posent côte à côte, une
     * « pleine » prend la largeur. C'est le seul endroit qui décide de la
     * grille — une tuile ne connaît que sa largeur voulue, pas ses voisines.
     *
     * @param list<Tuile> $tuiles
     */
    public static function tuiles(array $tuiles): string
    {
        if ($tuiles === []) {
            return '';
        }

        /* Une tuile seule prend la largeur ; à deux ou plus, elles se partagent
           la rangée. C'est ici que ça se décide et nulle part ailleurs — une
           tuile ne connaît que la largeur qu'elle demande, jamais ses voisines,
           et sans cette règle deux tuiles « pleine » feraient deux écrans. */
        $partage = count($tuiles) > 1;

        $html = '<div class="xo-grid" style="margin-top: 8px">';
        foreach ($tuiles as $tuile) {
            $html .= self::tuile($tuile, $partage);
        }

        return $html . '</div>';
    }

    /**
     * Une tuile : un résultat encadré, dans le fil de la conversation.
     *
     * Le contenu est recalculé à l'affichage (`Tuile::contenu()`) — rouvrir un
     * fil d'hier montre la veille de maintenant, pas une photo périmée.
     */
    public static function tuile(Tuile $tuile, bool $partage = false): string
    {
        $contenu = $tuile->contenu();
        $colonne = ($partage || $tuile->largeur === 'demi') ? 'xo-col-6' : 'xo-col-12';

        $corps = match ($tuile->type) {
            // Une recherche rend des dépêches, un survol rend des événements :
            // ce ne sont pas les mêmes objets, et l'arbre n'a pas de sens sur
            // un résultat classé par pertinence.
            Tuile::VEILLE => isset($contenu['depeches'])
                ? ($contenu['depeches'] === []
                    ? self::vide('silence', 'Aucune dépêche ne correspond à « ' . $contenu['q'] . ' ».')
                    : '<ul class="xo-list" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
                        . ' aria-label="Résultats">'
                        . self::lignes(array_map(
                            static fn (array $a): Piece => Piece::depeche($a),
                            $contenu['depeches'],
                        )) . '</ul>')
                : (($contenu['evenements'] ?? []) === []
                    ? self::vide('silence', 'Aucun événement ne correspond.')
                    : '<ul class="xo-list xo-list--tree" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
                        . ' aria-label="Veille">' . self::arbre($contenu['evenements']) . '</ul>'),

            Tuile::DEPECHE => self::inspecteur($contenu['article'] ?? null, $contenu['fratrie'] ?? []),

            Tuile::JOURNAL => self::activite($contenu['entrees'] ?? []),

            Tuile::ALERTES => self::alertes($contenu['groupes'] ?? [], $contenu['relance'] ?? []),

            Tuile::MEMOIRE => self::fils($contenu['fils'] ?? [], Agent::filId()),

            Tuile::LECTURE => self::lecture($contenu),

            Tuile::CORPUS => self::corpus($contenu['passages'] ?? [], (string) ($contenu['q'] ?? '')),

            default => self::vide('inconnu', 'Cette tuile ne sait rien montrer.'),
        };

        $compte = match ($tuile->type) {
            Tuile::VEILLE  => isset($contenu['depeches'])
                ? count($contenu['depeches']) . ' dépêches'
                : count($contenu['evenements'] ?? []) . ' événements',
            Tuile::ALERTES => count($contenu['groupes'] ?? []) . ' sur 6 h',
            Tuile::MEMOIRE => count($contenu['fils'] ?? []) . ' fils',
            Tuile::LECTURE => count($contenu['paragraphes'] ?? []) . ' paragraphes',
            Tuile::CORPUS  => count($contenu['passages'] ?? []) . ' passages',
            default        => '',
        };

        // La classe se compose avant l'attribut : coupée en deux dans un
        // `class="…"`, le linter n'y verrait qu'un nom tronqué.
        $classes = 'xo-panel'
            . (in_array($tuile->type, [Tuile::DEPECHE], true) ? ' xo-panel--pad' : '')
            . ' ' . $colonne;

        /* Une tuile ne dépasse jamais le tiers de la hauteur : au-delà, elle
           chasse le champ hors de l'écran et il faut faire défiler pour parler.
           Son contenu défile en elle, pas la page. */
        return '<section class="' . $classes . '" data-tuile="' . e($tuile->type) . '">'
            . '<h2 class="xo-panel__title">' . e($tuile->titre()) . '</h2>'
            . '<div class="xo-panel__body" style="--xo-max-h: 34vh">' . $corps . '</div>'
            . ($compte !== '' ? '<span class="xo-panel__count">' . e($compte) . '</span>' : '')
            . '</section>';
    }

    /**
     * Le bandeau du mode dépêche : la prochaine question partira avec elle.
     *
     * Rendu ici et non dans `Ecran` parce que deux chemins l'affichent — la
     * page qui s'ouvre sur `?depeche=N`, et « Interroger l'agent dessus » qui
     * le pose sans recharger. Deux gabarits auraient divergé au premier mot
     * changé d'un seul côté (règle 2).
     *
     * Tout état doit pouvoir se quitter : d'où la croix, qui appelle
     * `desancrer` comme n'importe quelle autre commande.
     *
     * @param array<string, mixed> $a
     */
    public static function ancre(array $a): string
    {
        return '<div class="xo-alert" role="status" id="bandeau-ancre" style="margin-bottom: 8px">'
            . '<span aria-hidden="true">' . self::glyphe((int) $a['niveau']) . '</span>'
            . '<span class="xo-alert__body">'
            . '<span class="xo-alert__title">À propos de cette dépêche.</span> '
            . e(Util::tronquer((string) $a['titre'], 110))
            . ' <span class="xo-faint">— ' . e((string) $a['source_nom']) . '</span>'
            . '</span>'
            . '<button class="xo-btn xo-btn--ghost" type="button" data-action="desancrer"'
            . ' data-xo-tip="Revenir à une conversation ordinaire" aria-label="Oublier cette dépêche">'
            . Icone::rendre('ecarter') . '</button>'
            . '</div>';
    }

    /**
     * Le texte d'un article, lu côté serveur.
     *
     * Ni cadre embarqué ni lien qui remplace l'écran : on rend les paragraphes
     * et rien d'autre. Encadrer la page d'origine aurait chargé ses publicités
     * et ses traceurs ici — NARH ne fait aucune requête vers l'extérieur depuis
     * le navigateur, et le lecteur est précisément ce qui rend cette posture
     * tenable plutôt que gênante.
     *
     * L'origine est affichée : lu à l'instant, ou relu depuis le corpus. Un
     * texte qui date sans le dire laisserait croire à une lecture fraîche.
     *
     * @param array<string, mixed> $contenu
     */
    public static function lecture(array $contenu): string
    {
        $a = $contenu['article'] ?? null;
        $paragraphes = $contenu['paragraphes'] ?? [];
        $origine = (string) ($contenu['origine'] ?? '');

        if ($a === null) {
            return self::vide('introuvable', 'Cette dépêche n\'est plus dans la veille.');
        }

        if ($paragraphes === []) {
            return self::vide('illisible', $origine === 'illisible'
                ? 'Le texte n\'a pas pu être récupéré (HTTP ' . (int) ($contenu['code'] ?? 0) . ') — mur payant ou page morte.'
                : 'Aucun paragraphe lisible dans cette page.');
        }

        $html = '<div class="xo-stack">'
            . '<p class="xo-muted">'
            . '<span class="xo-bold">' . e((string) $a['titre']) . '</span>'
            . '</p>'
            . '<p class="xo-hint">'
            . e((string) ($a['source_nom'] ?? '')) . ' · '
            . e(date('d/m H:i', (int) $a['date_tri'])) . ' · '
            . e($origine === 'corpus' ? 'relu depuis le corpus' : 'lu à l\'instant')
            . '</p>';

        foreach ($paragraphes as $p) {
            $html .= '<p>' . e((string) $p) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * Ce que le corpus retient : des passages, pas des titres.
     *
     * Le grain est le paragraphe — c'est toute la différence avec une recherche
     * dans la veille, qui rend des dépêches. Ici on montre le morceau de texte
     * qui répond, avec de quoi remonter à l'article.
     *
     * @param list<array<string, mixed>> $passages
     */
    public static function corpus(array $passages, string $q): string
    {
        if ($passages === []) {
            return self::vide(
                'silence',
                $q === ''
                    ? 'Le corpus est vide. Il se remplit par « php cli.php --ingerer ».'
                    : 'Aucun passage ne parle de « ' . $q . ' ».'
            );
        }

        /* Un passage est une pièce, comme une dépêche ou un tour — c'est le
           troisième état, *retenu*. Il se rend donc par `ligne()` comme tout le
           reste : lui donner un gabarit à lui obligerait l'œil à réapprendre à
           lire en passant du corpus à la veille. */
        return '<ul class="xo-list" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
            . ' aria-label="Passages">'
            . self::lignes(array_map(
                static fn (array $p): Piece => Piece::passage($p),
                $passages,
            )) . '</ul>';
    }

    /**
     * Les sources d'un tour — le pont de retour vers la veille.
     *
     * Ce sont les dépêches que les **outils ont réellement rapportées**, pas
     * celles que le modèle cite : on ne lui demande pas ses liens, il les
     * écorcherait, et une URL inventée est pire qu'une absence de source.
     *
     * Elles se rendent avec la ligne du fil, sans exception : une dépêche citée
     * sous une réponse et la même dépêche dans la veille doivent se lire de la
     * même façon — sinon l'œil ne fait pas le lien, et le pont n'existe que
     * dans le code. Le clic y renvoie (`data-depeche`, câblé dans narh.js).
     *
     * Le dédoublonnage n'est pas cosmétique : la même dépêche remonte souvent
     * par trois agrégateurs, et une liste qui répète trois fois le même titre
     * ne se lit plus.
     *
     * @param list<array<string, mixed>> $etapes
     */
    private static function sources(array $etapes): string
    {
        $pieces = [];
        $vus = [];

        foreach ($etapes as $etape) {
            if (!is_array($etape['resultat'] ?? null)) {
                continue;
            }
            foreach ($etape['resultat'] as $a) {
                if (!is_array($a) || !isset($a['id'], $a['titre'])) {
                    continue;
                }
                $cle = mb_strtolower(trim((string) $a['titre']));
                if (isset($vus[$cle])) {
                    continue;
                }
                $vus[$cle] = true;
                $pieces[] = Piece::depeche($a);
            }
        }

        if ($pieces === []) {
            return '';
        }

        return '<div class="xo-rule xo-rule--start">Sources</div>'
            . '<ul class="xo-list" data-sources role="listbox" aria-label="Sources de la réponse">'
            . self::lignes($pieces) . '</ul>';
    }

    /**
     * La conversation entière, du premier tour au dernier.
     *
     * @param list<array<string, mixed>> $tours
     */
    /**
     * Les tours seuls, sans leur `<ul>`.
     *
     * Le flux est **unique** : les segments du direct et les tours de
     * conversation vivent dans la même liste, et c'est la page qui la possède.
     * Rendre le `<ul>` ici obligerait à le remplacer en entier à chaque
     * rafraîchissement — et emporterait les segments au passage.
     *
     * @param list<array<string, mixed>> $tours
     */
    public static function tours(array $tours): string
    {
        $html = '';
        foreach (array_reverse($tours) as $t) {
            $html .= self::tour($t);
        }

        return $html;
    }

    public static function conversation(array $tours): string
    {
        if ($tours === []) {
            return self::vide('muet', 'Poser une question : la conversation commence ici.');
        }

        /* Le plus récent en tête, comme le direct.

           Les deux régimes se lisent alors dans le même sens : ce qui vient
           d'arriver est toujours sous le champ, sans avoir à faire défiler. Le
           prix est assumé — une réponse se lit au-dessus de sa question, ce qui
           surprend une fois puis ne se remarque plus, alors que chercher la
           dernière ligne en bas d'un fil de trente tours se paie à chaque
           consultation. */
        $html = '<ul class="xo-timeline">';
        foreach (array_reverse($tours) as $t) {
            $html .= self::tour($t);
        }

        return $html . '</ul>';
    }

    /* ---- Le direct ---------------------------------------------------------- */

    /**
     * Un segment d'antenne.
     *
     * Même grammaire que tout le reste : un marqueur, un acteur, une heure, un
     * corps. Ce qui change d'un tour de conversation, c'est que l'acteur est le
     * direct et que la nature du segment se lit — on doit savoir en un coup
     * d'œil si NARH annonce du neuf ou s'il relance faute de mieux. Le cacher
     * ferait passer une relance pour une information.
     *
     * @param array{nature: string, lancement: string, texte: string, pieces: list<Piece>} $seg
     */
    public static function segment(array $seg, string $heure): string
    {
        $nature = (string) $seg['nature'];
        [$glyphe, $ton] = match ($nature) {
            'alerte'  => ['●', 'danger'],
            'depeche' => ['▸', 'accent'],
            'bref'    => ['≡', 'info'],
            'point'   => ['◆', 'special'],
            default   => ['↻', 'muted'],
        };

        $classeLancement = 'xo-bold xo-' . $ton;

        $lignes = '';
        if (($seg['pieces'] ?? []) !== []) {
            $lignes = '<ul class="xo-list" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
                . ' aria-label="Sujets du segment">' . self::lignes($seg['pieces']) . '</ul>';
        }

        /* La voix se pose ici, quelques secondes après le segment : le
           conteneur est rendu vide par PHP, le navigateur n'aura qu'un texte à
           y mettre. Il ne dessine donc rien de plus (règle 2). */
        return '<li class="xo-timeline__item" data-segment="' . e($nature) . '"'
            . ' data-rang="' . (int) ($seg['rang'] ?? 0) . '"'
            . ' data-quand="' . time() . '">'
            . '<span class="xo-timeline__marker" aria-hidden="true">' . $glyphe . '</span>'
            . '<div class="xo-timeline__body">'
            . '<div><span class="' . $classeLancement . '">' . e((string) $seg['lancement']) . '</span> '
            . '<span class="xo-timeline__time">' . e($heure) . '</span></div>'
            . '<div>' . e((string) $seg['texte']) . '</div>'
            /* La voix porte le glyphe de l'agent, comme dans une conversation :
               ce que dit le modèle doit se distinguer à l'œil de ce que la
               collecte a établi. C'est la règle 4 transposée — son avis
               s'affiche à côté du fait, jamais confondu avec lui. */
            . '<div class="xo-muted" hidden><span aria-hidden="true">› </span><span data-voix></span></div>'
            . $lignes
            . '</div>'
            . '</li>';
    }

    /**
     * La note de quart — ce que le direct laisse derrière lui.
     *
     * C'est la trace que la boucle doit produire : sans elle, une heure
     * d'antenne ne laisserait qu'un journal de lignes techniques. Ce qu'on veut
     * transmettre à qui prend la suite, c'est ce qui a été couvert, et combien
     * de fois — un sujet repris quatre fois n'a pas le même statut qu'un sujet
     * cité une fois.
     *
     * @param array{segments: int, sujets: int, duree: int, couvert: list<array<string, mixed>>} $bilan
     */
    public static function noteDeQuart(array $bilan): string
    {
        $html = '<div class="xo-row">'
            . '<div class="xo-stat"><span class="xo-stat__value">' . (int) $bilan['segments'] . '</span>'
            . '<span class="xo-stat__label">segments</span></div>'
            . '<div class="xo-stat"><span class="xo-stat__value">' . (int) $bilan['sujets'] . '</span>'
            . '<span class="xo-stat__label">sujets</span></div>'
            . '<div class="xo-stat"><span class="xo-stat__value">'
            . e(Util::duree(((int) $bilan['duree']) * 1000)) . '</span>'
            . '<span class="xo-stat__label">antenne</span></div>'
            // Le coût de la voix : à une phrase toutes les onze secondes, il
            // n'est pas anecdotique, et c'est le seul endroit qui le totalise.
            . '<div class="xo-stat"><span class="xo-stat__value">' . (int) ($bilan['jetons'] ?? 0) . '</span>'
            . '<span class="xo-stat__label">jetons</span></div>'
            . '</div>';

        if (($bilan['couvert'] ?? []) === []) {
            return $html . '<p class="xo-muted">Aucun sujet n\'a été traité.</p>';
        }

        $pieces = [];
        foreach ($bilan['couvert'] as $g) {
            $piece = Piece::evenement($g);
            $fois = (int) ($g['fois'] ?? 1);
            $pieces[] = $fois > 1 ? $piece->avecMeta('repris ×' . $fois) : $piece;
        }

        return $html
            . '<div class="xo-rule xo-rule--start">Couvert à l\'antenne</div>'
            . '<ul class="xo-list" data-xo-list data-xo-menu="#menu-narh" role="listbox"'
            . ' aria-label="Sujets couverts">' . self::lignes($pieces) . '</ul>';
    }

    /* ---- Les outils ---------------------------------------------------------
       L'onglet Outils du Newsdesk : ce que l'agent a réellement fait, et de quoi
       le refaire soi-même. La trace reste dans la conversation — ici on la
       relit autrement, comme un poste de commande plutôt qu'un récit. */

    /**
     * Les appels passés, avec ce qu'on peut en faire.
     *
     * Les gestes dépendent du type : « Voir » n'a de sens que pour un outil qui
     * a une contrepartie visuelle, et c'est `Outils` qui le sait. Proposer un
     * bouton inerte partout ferait croire qu'il manque quelque chose.
     *
     * @param list<array{outil: string, arguments: array, ok: bool, resultat: mixed, heure: string}> $appels
     */
    public static function outils(array $appels): string
    {
        if ($appels === []) {
            return '<p class="xo-muted">Aucun outil appelé dans ce fil.</p>';
        }

        /* `xo-list` et non `xo-log` : le composant journal réserve cinq
           caractères au niveau (« warn », « error »), et `rechercher_actualites`
           en fait vingt-et-un — les colonnes se chevauchaient. Ici on reprend la
           grammaire de ligne du reste de l'application : issue, instant, acteur,
           titre, mesure. Un appel d'outil est une chose qui est arrivée, il se
           lit comme les autres. */
        // `max-height` en clair : `xo-list` ne lit pas `--xo-max-h`, et la
        // liste grandissait sans fin à mesure que l'agent appelait des outils.
        $html = '<ul class="xo-list xo-scroll" style="max-height: 14vh">';

        foreach ($appels as $i => $a) {
            $meta = Outils::metadonnees($a['outil']);
            $champ = $meta['champ'] ?? null;
            $valeur = $champ !== null ? trim((string) ($a['arguments'][$champ] ?? '')) : '';

            // Le résultat en un mot : un nombre, un échec, ou rien à compter.
            [$dit, $ton] = match (true) {
                !$a['ok']                => ['échec', 'danger'],
                is_array($a['resultat']) => [count($a['resultat']) . ' résultats', 'success'],
                default                  => ['rendu', 'muted'],
            };

            $classeIcone = 'xo-list__icon xo-' . $ton;
            $peutVoir = Outils::tuilePour($a['outil'], $a['arguments']) !== null;

            $html .= '<li class="xo-list__item" data-appel="' . $i . '"'
                . ' data-outil="' . e($a['outil']) . '"'
                . ' data-valeur="' . e($valeur) . '">'
                . '<span class="' . $classeIcone . '" aria-hidden="true">' . ($a['ok'] ? '⚙' : '✗') . '</span>'
                . '<span class="xo-faint xo-nowrap" style="flex: none">' . e($a['heure']) . '</span>'
                . '<span class="xo-muted xo-nowrap" style="flex: none; width: 16ch">'
                . e(Util::tronquer($a['outil'], 16)) . '</span>'
                . '<span>' . ($valeur !== '' ? e(Util::tronquer($valeur, 44)) : '<span class="xo-faint">—</span>') . '</span>'
                . '<span class="xo-list__meta">' . e($dit) . '</span>'
                . '<button class="xo-btn xo-btn--ghost" type="button" data-outil-geste="voir"'
                . ($peutVoir ? '' : ' disabled')
                . ' data-xo-tip="Poser la tuile de cet appel">voir</button>'
                . '<button class="xo-btn xo-btn--ghost" type="button" data-outil-geste="rejouer"'
                . ' data-xo-tip="Relancer maintenant — l’actualité a bougé">rejouer</button>'
                . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * Le formulaire de lancement, dessiné depuis le schéma de l'outil.
     *
     * Le champ, son aide et son caractère obligatoire viennent de
     * `Outils::metadonnees()`, donc des définitions données au modèle. Un outil
     * sans paramètre n'affiche pas de champ — proposer une case vide à remplir
     * pour « heure_actuelle » serait demander ce dont on n'a pas besoin.
     */
    public static function formulaireOutil(): string
    {
        $options = '';
        foreach (Outils::noms() as $nom) {
            $options .= '<option value="' . e($nom) . '">' . e($nom) . '</option>';
        }

        // Les aides et les champs de chaque outil, pour que le navigateur
        // adapte le formulaire sans redemander au serveur.
        $schema = [];
        foreach (Outils::noms() as $nom) {
            $meta = Outils::metadonnees($nom);
            $schema[$nom] = [
                'champ'  => $meta['champ'],
                'aide'   => $meta['aide'],
                'requis' => $meta['requis'],
            ];
        }

        return '<div class="xo-rule xo-rule--start">Lancer un outil</div>'
            . '<div class="xo-row" data-outil-formulaire'
            . " data-schema='" . e(json_encode($schema, JSON_UNESCAPED_UNICODE)) . "'>"
            . '<label class="xo-field xo-field--inline" style="margin: 0">'
            . '<span class="xo-sr">Outil</span>'
            . '<select class="xo-select" id="outil-nom" aria-label="Outil">' . $options . '</select>'
            . '</label>'
            . '<label class="xo-search" style="flex: 1 1 20ch; min-width: 12ch">'
            . '<span class="xo-search__prefix" aria-hidden="true">›</span>'
            . '<input type="text" id="outil-valeur" aria-label="Argument">'
            . '</label>'
            . '<button class="xo-btn xo-btn--ghost" type="button" id="outil-lancer">Lancer</button>'
            . '</div>'
            . '<p class="xo-hint" id="outil-aide"></p>';
    }

    /* ---- L'inspecteur ------------------------------------------------------ */

    /**
     * L'objet sélectionné, quelle que soit sa nature.
     *
     * Quatre blocs, toujours dans cet ordre : ce que c'est, ce que ça dit, ce
     * qu'on en sait, qui d'autre en parle. Un tour de conversation prendra la
     * même forme en P2 — l'entête portera le modèle et le coût, la chronologie
     * portera les outils appelés.
     *
     * @param array<string, mixed>|null  $a       la dépêche, ou null
     * @param list<array<string, mixed>> $fratrie les autres rédactions
     */
    public static function inspecteur(?array $a, array $fratrie = []): string
    {
        if ($a === null) {
            return self::vide('rien', "Choisir une ligne : l'inspecteur suit.");
        }

        $niveau = (int) $a['niveau'];
        $ton = self::TONS[$niveau] ?? 'faint';
        $classeBadge = 'xo-badge xo-badge--solid xo-badge--' . ($niveau >= Piece::FORT ? $ton : 'info');

        /* -- Ce que c'est, et ce que ça dit : deux lignes, pas trois --
           La source occupait une ligne à elle seule, en séparateur, entre le
           badge et le titre. Trois lignes d'en-tête avant le premier mot utile,
           dans une zone bornée à 16vh : le titre et le résumé se lisaient dans
           ce qu'il restait. Le nom de la rédaction est une **étiquette**, pas
           une rupture — il tient à côté du niveau, qu'il qualifie. */
        $html = '<div class="xo-row">'
            . '<span class="' . $classeBadge . '">'
            . (self::GLYPHES[Piece::DEPECHE][$niveau] ?? '·') . ' '
            . e(mb_strtoupper(Alerte::nom($niveau)))
            . '</span>'
            . '<span class="xo-muted">' . e((string) $a['source_nom']) . '</span>'
            . '<span class="xo-spacer"></span>'
            . '<span class="xo-faint">' . e(Util::age((int) $a['date_tri'])) . '</span>'
            . '</div>'
            . '<p class="xo-bold">' . e((string) $a['titre']) . '</p>';

        if (trim((string) ($a['resume'] ?? '')) !== '') {
            $html .= '<p class="xo-muted">' . e(Util::tronquer((string) $a['resume'], 320)) . '</p>';
        }

        /* -- Ce qu'on en sait --
           Les motifs sont ce qui explique le score : sans eux, le niveau est un
           chiffre qu'on subit. */
        $faits = [
            'Publié'  => date('d/m H:i', (int) $a['date_tri']),
            'Relevé'  => date('H:i', (int) $a['vu']),
            'Domaine' => Util::domaine((string) $a['lien']),
        ];
        if (trim((string) ($a['motifs'] ?? '')) !== '') {
            $faits['Motifs'] = (string) $a['motifs'];
        }
        if ($fratrie !== []) {
            $faits['Reprises'] = (count($fratrie) + 1) . ' rédactions';
        }

        $html .= '<dl class="xo-kv">';
        foreach ($faits as $cle => $valeur) {
            $html .= '<div class="xo-kv__row">'
                . '<dt>' . e($cle) . '</dt>'
                . '<span class="xo-kv__leader" aria-hidden="true"></span>'
                . '<dd>' . e($valeur) . '</dd>'
                . '</div>';
        }
        $html .= '</dl>';

        $html .= '<div class="xo-row" style="margin-top: 8px">'
            . '<a class="xo-btn xo-btn--ghost" href="' . e((string) $a['lien']) . '"'
            . ' target="_blank" rel="noopener noreferrer">Ouvrir l\'article</a>'
            . '</div>';

        /* -- Qui d'autre en parle --
           C'est là qu'on voit une rédaction titrer « censure » quand une autre
           écrit « valide l'essentiel ». La chronologie, pas la liste : l'ordre
           d'arrivée est l'information. */
        if ($fratrie !== []) {
            $html .= '<div class="xo-rule xo-rule--start">Ailleurs</div>'
                . '<ul class="xo-timeline">';

            foreach ($fratrie as $f) {
                $html .= '<li class="xo-timeline__item" data-value="' . (int) $f['id'] . '">'
                    . '<span class="xo-timeline__marker" aria-hidden="true">○</span>'
                    . '<div class="xo-timeline__body">'
                    . '<div>' . e(Util::tronquer((string) $f['titre'], 90)) . '</div>'
                    . '<div class="xo-timeline__time">'
                    . e((string) $f['source_nom']) . ' · ' . e(date('H:i', (int) $f['date_tri']))
                    . '</div>'
                    . '</div>'
                    . '</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /* ---- L'utilisateur ------------------------------------------------------ */

    /**
     * Un avatar en pixels, dérivé du nom.
     *
     * Des caractères pleins dans une grille, pas une image : NARH n'a ni build
     * ni ressource externe, et un `<img>` demanderait un fichier à stocker et à
     * servir. Le motif se calcule depuis une empreinte du nom — le même nom
     * redonne toujours le même visage, sans que rien ne soit conservé.
     *
     * Symétrique par construction, comme les identicônes : une moitié tirée au
     * sort et miroir. Un motif asymétrique de huit pixels de côté ne se lit pas
     * comme un visage, il se lit comme du bruit.
     */
    public static function avatar(string $nom, int $taille = 6): string
    {
        $graine = md5(mb_strtolower(trim($nom)));

        // La teinte sort de la même empreinte : deux utilisateurs différents
        // n'ont ni le même motif ni la même couleur.
        $tons = ['accent', 'info', 'success', 'special', 'alt'];
        $ton = $tons[hexdec(substr($graine, 0, 2)) % count($tons)];

        $moitie = (int) ceil($taille / 2);
        $lignes = [];

        for ($y = 0; $y < $taille; $y++) {
            /* Les cellules sont collectées dans un tableau, jamais découpées
               dans une chaîne : `str_split` compte des octets, et « █ » en pèse
               trois — le miroir coupait les caractères en deux et rendait du
               charabia. Même piège que `str_pad`, sous un autre visage. */
            $cellules = [];
            for ($x = 0; $x < $moitie; $x++) {
                // Un caractère d'empreinte par cellule : sa parité décide.
                $cellules[] = (hexdec($graine[($y * $moitie + $x) % 32]) % 2 === 0) ? '██' : '  ';
            }

            // La cellule fait deux caractères de large : dans une grille
            // monospace, c'est ce qui la rend carrée plutôt qu'allongée.
            $lignes[] = implode('', $cellules) . implode('', array_reverse($cellules));
        }

        // La classe se compose avant l'attribut : coupée en deux dans un
        // `class="…"`, le linter n'y verrait qu'un nom tronqué.
        $classe = 'xo-empty__art xo-' . $ton;

        return '<pre class="' . $classe . '" aria-hidden="true" style="line-height: .75">'
            . e(implode("\n", $lignes)) . '</pre>';
    }

    /**
     * La section Utilisateur : qui regarde, ce qu'il a consommé, ce qu'il peut
     * faire tout de suite.
     *
     * Elle remplace les compteurs de collecte, qui disaient déjà leur affaire
     * dans la barre d'état : afficher deux fois le même chiffre à trente
     * centimètres d'écart, c'est demander lequel croire.
     *
     * @param array{fils: int, tours: int, jetons: int} $stats
     */
    public static function utilisateur(string $nom, array $stats, bool $antenne = false): string
    {
        return '<div class="xo-row" style="align-items: flex-start">'
            . self::avatar($nom)
            . '<div class="xo-stack xo-stack--tight" style="flex: 1">'
            . '<div class="xo-row"><span class="xo-bold">' . e($nom) . '</span>'
            . '<span class="xo-spacer"></span>' . self::regime($antenne) . '</div>'
            . '<div class="xo-faint">' . (int) $stats['fils'] . ' fils · '
            . (int) $stats['tours'] . ' tours · ' . (int) $stats['jetons'] . ' jetons</div>'
            . '<div id="jauge-contexte">' . self::contexte(0, 0) . '</div>'
            . '</div>'
            . '</div>';
    }

    /**
     * L'étiquette du régime — le seul endroit qui le dise.
     *
     * En couleur pleine parce que c'est un état, pas une mesure : on doit
     * savoir d'un coup d'œil, sans lire, si NARH parle tout seul ou s'il
     * attend une question. Le rouge n'est pas une alerte ici — c'est la
     * convention du direct, celle du voyant d'un studio.
     *
     * La barre d'état ne le répète pas : un même état affiché à deux endroits
     * finit par diverger, et il faut alors deviner lequel croire.
     */
    public static function regime(bool $antenne): string
    {
        /* Deux étiquettes pleines, pas une pleine et une pâle : ce sont deux
           régimes de même rang, pas un état normal et une exception. Le rouge
           est la convention du direct, le vert celle d'une ligne libre. */
        $classe = $antenne
            ? 'xo-badge xo-badge--solid xo-badge--danger'
            : 'xo-badge xo-badge--solid xo-badge--success';

        /* L'étiquette **est** la bascule.

           Un état qu'on lit et un bouton qui le change, séparés, c'est deux
           choses à trouver pour une seule idée. Ici on clique ce qu'on lit —
           et l'onglet Antenne, qui ne servait plus qu'à ça, a disparu.

           Un `<button>` et pas un `<span>` : le clavier doit l'atteindre, et un
           changement de régime n'est pas un geste à réserver à la souris. */
        return '<button type="button" class="' . $classe . '" id="etat-regime"'
            . ' data-action="' . ($antenne ? 'conversation' : 'direct') . '"'
            . ' data-xo-tip="' . ($antenne ? "Couper l'antenne" : 'Passer en direct') . '">'
            . ($antenne ? '● EN DIRECT' : '○ CONVERSATION')
            . '</button>';
    }

    /**
     * La fenêtre de contexte, en jauge.
     *
     * C'est la mesure qui manque le plus à une console d'agent : un fil se
     * dégrade sans prévenir quand il approche de la fenêtre — le modèle perd le
     * début, répond à côté, et rien à l'écran ne dit pourquoi. Une jauge le dit
     * avant que ça n'arrive.
     *
     * Ce qui est mesuré, ce sont les jetons **relus** au dernier tour, pas la
     * somme des tours : le contexte est reconstruit à chaque envoi, il ne
     * s'accumule pas.
     *
     * Les seuils sont ceux de la charte (`XOSHUI/docs/api.md`, § Indicateurs) :
     * avertissement à 70 %, danger à 90 %, et **toujours la valeur chiffrée à
     * côté** — un remplissage seul ne se lit pas.
     *
     * @param int $utilise jetons relus au dernier tour
     * @param int $fenetre taille de la fenêtre du modèle chargé, 0 si inconnue
     */
    public static function contexte(int $utilise, int $fenetre): string
    {
        if ($fenetre <= 0 || $utilise <= 0) {
            /* Tant qu'aucun tour n'a été joué ou que le moteur est déchargé, la
               fenêtre est inconnue. On le dit — une jauge à zéro laisserait
               croire qu'elle est vide, ce qui n'est pas la même chose. */
            return '<div class="xo-progress">'
                . '<span class="xo-progress__label">contexte</span>'
                . '<span class="xo-faint">—</span>'
                . '</div>';
        }

        $pct = (int) round(min(100, $utilise / $fenetre * 100));
        $ton = match (true) {
            $pct >= 90 => ' xo-progress--danger',
            $pct >= 70 => ' xo-progress--warning',
            default    => '',
        };

        // La classe se compose avant l'attribut : coupée en deux dans un
        // `class="…"`, le linter n'y verrait qu'un nom tronqué.
        $classe = 'xo-progress' . $ton;
        $dit = self::milliers($utilise) . ' / ' . self::milliers($fenetre);

        return '<div class="' . $classe . '">'
            . '<span class="xo-progress__label">contexte</span>'
            . '<div class="xo-progress__track" role="meter"'
            . ' aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"'
            . ' aria-label="Fenêtre de contexte : ' . e($dit) . ' jetons">'
            . '<div class="xo-progress__fill" style="width: ' . $pct . '%"></div>'
            . '</div>'
            . '<span class="xo-progress__value" data-xo-tip="' . e($dit) . ' jetons relus">' . $pct . '%</span>'
            . '</div>';
    }

    /** 12480 → « 12,5 k » : dans cinq caractères, l'ordre de grandeur suffit. */
    private static function milliers(int $n): string
    {
        return $n < 1000 ? (string) $n : number_format($n / 1000, 1, ',', '') . ' k';
    }

    /* ---- Fragments communs ------------------------------------------------- */

    /**
     * Un cadre vide, avec son mot au centre.
     *
     * `str_pad` compte des octets : « événement » y perdrait deux colonnes et le
     * cadre sortirait de la grille. La largeur se mesure en caractères.
     */
    public static function vide(string $mot, string $message): string
    {
        $interieur = '  ' . $mot . '  ';
        $filet = str_repeat('─', mb_strlen($interieur));

        return '<div class="xo-empty">'
            . '<pre class="xo-empty__art" aria-hidden="true">'
            . '┌' . $filet . "┐\n"
            . '│' . e($interieur) . "│\n"
            . '└' . $filet . '┘</pre>'
            . '<p class="xo-empty__msg xo-muted">' . e($message) . '</p>'
            . '</div>';
    }

    /**
     * Le gabarit d'un tour en attente.
     *
     * Un tour provisoire, **à la forme du tour définitif** : même marqueur,
     * même acteur, même place dans la chronologie. Quand la réponse arrive, le
     * serveur la remplace sans que rien ne saute — c'est tout l'intérêt.
     *
     * Le bloc de texte brut qui tenait ce rôle grossissait au fil des jetons
     * puis disparaissait d'un coup pour laisser place à un tour d'une autre
     * forme : deux secousses pour une seule réponse.
     *
     * `xo-skeleton` occupe la ligne avant le premier jeton, `xo-spinner` dit
     * que quelque chose se passe, et la phase dit quoi — le modèle peut mettre
     * plusieurs secondes à charger avant d'émettre son premier mot.
     */
    public static function gabaritAttente(): string
    {
        return '<template id="gabarit-attente">'
            . '<li class="xo-timeline__item" data-attente>'
            . '<span class="xo-timeline__marker xo-accent" aria-hidden="true">›</span>'
            . '<div class="xo-timeline__body">'
            . '<div class="xo-row">'
            . '<span class="xo-bold">NARH</span>'
            . '<span class="xo-timeline__time" data-heure></span>'
            . '<span class="xo-spinner" aria-hidden="true"></span>'
            /* Le mot suffit : le rotor dit déjà qu'on attend, et une icône qui
               change en même temps que le mot répète la même chose deux fois
               dans deux langues. */
            . '<span class="xo-faint" data-phase></span>'
            . '</div>'
            . '<div data-texte><span class="xo-skeleton" style="width: 28ch">&nbsp;</span></div>'
            . '</div>'
            . '</li>'
            . '</template>';
    }

    /**
     * Le gabarit d'une notification.
     *
     * Rendu par PHP plutôt qu'assemblé dans narh.js : le JS le clone et remplit
     * deux textes. C'est la règle 2 tenue jusqu'au bout — le navigateur place du
     * balisage, il n'en écrit pas.
     */
    public static function gabaritToast(): string
    {
        return '<template id="gabarit-toast">'
            . '<div class="xo-toast" role="status" data-xo-toast="4000">'
            . '<span aria-hidden="true">·</span>'
            // Le détail est un simple nœud de texte : XOSHUI n'a pas de classe
            // pour lui, et en inventer une serait une classe maison.
            . '<span class="xo-toast__body"><span class="xo-toast__title"></span> </span>'
            . '<button class="xo-toast__close" type="button" aria-label="Fermer">×</button>'
            . '</div>'
            . '</template>';
    }

    /**
     * La fraîcheur, en opacité.
     *
     * Ce qui vient d'arriver ressort, ce qui date s'efface — la profondeur
     * temporelle sans une colonne de plus. Plage courte et plancher haut : au-delà
     * le décor deviendrait illisible, et la règle du projet interdit de descendre
     * du texte utile sous le contraste minimum. À ne poser que sur l'instant et
     * l'acteur, jamais sur le titre.
     */
    private static function fraicheur(int $quand): string
    {
        $fenetre = max(60, (int) narh_reglage('debit_fenetre', 180) * 60);
        $part = min(1.0, max(0, time() - $quand) / $fenetre);

        return ' --xo-fade: ' . round(1 - 0.4 * $part, 2) . ';';
    }
}
