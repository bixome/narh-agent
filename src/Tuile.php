<?php
declare(strict_types=1);

/**
 * La tuile — un résultat encadré, posé dans la conversation.
 *
 * NARH n'a plus qu'une lecture : la conversation. La veille, la mémoire,
 * l'inspecteur et le journal ne sont plus des écrans où l'on va, mais des
 * **tuiles** qui apparaissent dans le fil quand la demande — ou l'agent — en a
 * besoin. Un écran séparé oblige à quitter ce qu'on lisait pour aller chercher ;
 * une tuile arrive à l'endroit où la question a été posée, et y reste comme
 * trace de la réponse.
 *
 * Une tuile ne stocke pas ce qu'elle montre : elle stocke **de quoi le
 * refaire** — un type et ses paramètres. C'est ce qui permet de rouvrir un fil
 * d'hier et d'y voir la veille telle qu'elle est maintenant, pas une photo
 * périmée. Le rendu, lui, reste en PHP (règle 2) : `Vue::tuile()` la dessine,
 * le navigateur ne fait que la poser.
 *
 * Le descripteur est volontairement minuscule — un type, quelques clés — parce
 * qu'il voyage en JSON dans la table `message` et se relit à chaque affichage.
 */
final class Tuile
{
    /** Le fil de la veille, filtré. */
    public const VEILLE = 'veille';

    /** Une dépêche et ses reprises — ce que montrait l'inspecteur. */
    public const DEPECHE = 'depeche';

    /** La chronologie commune : collecte et agent (règle 7). */
    public const JOURNAL = 'journal';

    /** Les événements de niveau ≥ alerte sur six heures. */
    public const ALERTES = 'alertes';

    /** Ce qui est retenu : les fils passés. */
    public const MEMOIRE = 'memoire';

    public const TITRES = [
        self::VEILLE  => 'Veille',
        self::DEPECHE => 'Dépêche',
        self::JOURNAL => 'Journal',
        self::ALERTES => 'Alertes',
        self::MEMOIRE => 'Mémoire',
    ];

    /**
     * @param string               $type      l'une des constantes ci-dessus
     * @param array<string, mixed> $params    de quoi refaire le contenu
     * @param string               $largeur   'pleine' ou 'demi' — le fractionnement
     */
    public function __construct(
        public readonly string $type,
        public readonly array $params = [],
        public readonly string $largeur = 'pleine',
    ) {
    }

    /** @param array<string, mixed> $brut */
    public static function depuisTableau(array $brut): ?self
    {
        $type = (string) ($brut['type'] ?? '');
        if (!isset(self::TITRES[$type])) {
            return null;
        }

        return new self(
            $type,
            is_array($brut['params'] ?? null) ? $brut['params'] : [],
            ($brut['largeur'] ?? '') === 'demi' ? 'demi' : 'pleine',
        );
    }

    /** @return array<string, mixed> */
    public function enTableau(): array
    {
        return ['type' => $this->type, 'params' => $this->params, 'largeur' => $this->largeur];
    }

    public function titre(): string
    {
        return self::TITRES[$this->type] ?? 'Résultat';
    }

    /**
     * Ce que la tuile montre, calculé maintenant.
     *
     * La lecture de la base vit ici et pas dans `Vue` : la vue reçoit un
     * résultat, elle ne va pas le chercher (règle « une règle, un endroit »).
     *
     * @return array<string, mixed>
     */
    public function contenu(): array
    {
        return match ($this->type) {
            self::VEILLE  => $this->contenuVeille(),
            self::DEPECHE => $this->contenuDepeche(),
            self::JOURNAL => ['entrees' => Journal::lire((int) ($this->params['limite'] ?? 40))],
            self::ALERTES => $this->contenuAlertes(),
            self::MEMOIRE => ['fils' => Memoire::fils(30, Agent::filId())],
            default       => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function contenuVeille(): array
    {
        $base = new Base((string) narh_reglage('base_veille'));
        $limite = (int) ($this->params['limite'] ?? 25);
        $q = trim((string) ($this->params['q'] ?? ''));

        /* Une recherche cherche **comme l'outil**, mot à mot.

           Mesuré : pour « incendies Belgique pollution », l'outil rendait dix
           dépêches et la tuile zéro — parce qu'elle passait par `arbre()`, dont
           le filtre `q` fait un LIKE sur la phrase entière. On posait donc une
           boîte « silence » juste sous une réponse qui citait dix sources.
           Deux façons de chercher pour une même question, c'était une de trop. */
        if ($q !== '') {
            return ['depeches' => $base->chercherParMots($q, $limite), 'q' => $q];
        }

        $filtres = [
            'rubrique' => (string) ($this->params['rubrique'] ?? 'tout'),
            'niveau'   => (int) ($this->params['niveau'] ?? 0),
            'statut'   => (string) ($this->params['statut'] ?? ''),
            'tri'      => 'publication',
        ];

        return ['evenements' => $base->arbre($filtres, $limite), 'filtres' => $filtres];
    }

    /** @return array<string, mixed> */
    private function contenuDepeche(): array
    {
        $base = new Base((string) narh_reglage('base_veille'));
        $a = $base->article((int) ($this->params['id'] ?? 0));

        return [
            'article' => $a,
            'fratrie' => $a !== null && $a['groupe_id'] !== null
                ? $base->fratrie((int) $a['groupe_id'], (int) $a['id'])
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function contenuAlertes(): array
    {
        $base = new Base((string) narh_reglage('base_veille'));
        $maintenant = time();

        return [
            'groupes' => $base->alertes($maintenant - 21600, Alerte::ALERTE, (int) narh_reglage('alertes_max', 12)),
            'relance' => array_map('intval', array_column($base->aRelancer(
                $maintenant,
                (int) narh_reglage('relance_minutes', 45),
                (int) narh_reglage('relance_niveau', Alerte::ALERTE)
            ), 'id')),
        ];
    }
}
