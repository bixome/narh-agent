<?php
declare(strict_types=1);

/**
 * Le rapprochement des dépêches.
 *
 * Le cœur du signal « alerte info ». Un fait important n'est pas un titre
 * alarmant : c'est un titre que plusieurs rédactions indépendantes publient
 * dans le même quart d'heure. Encore faut-il reconnaître que « Séisme de
 * magnitude 6,1 au large de la Crète » et « Un fort séisme secoue la Crète »
 * parlent de la même chose.
 *
 * La méthode : comparer les ensembles de mots significatifs (indice de
 * Jaccard). Pas de vecteurs, pas de modèle — sur des titres de presse, longs
 * d'une douzaine de mots dont trois ou quatre sont des noms propres, le
 * recouvrement brut sépare correctement.
 *
 * Le coût, lui, vient du nombre de comparaisons : quelques centaines de
 * dépêches par cycle contre un millier de groupes ouverts font un million de
 * comparaisons. D'où l'index inversé, monté une fois par cycle : un groupe
 * n'est comparé que s'il partage au moins deux mots avec la dépêche. Le
 * million tombe à quelques milliers.
 */
final class Regroupeur
{
    /** @var array<int, array{id: int, jetons: list<string>, titre: string}> */
    private array $groupes = [];

    /** mot → identifiants de groupes qui le contiennent @var array<string, list<int>> */
    private array $index = [];

    private float $seuil;

    public function __construct(private readonly Base $base, int $maintenant)
    {
        $this->seuil = (float) narh_reglage('similarite', 0.42);
        $fenetre = (int) narh_reglage('fenetre', 10800);

        foreach ($this->base->groupesActifs($maintenant - $fenetre) as $g) {
            $this->ajouterIndex((int) $g['id'], explode(' ', (string) $g['jetons']), (string) $g['titre']);
        }
    }

    /** @param list<string> $jetons */
    private function ajouterIndex(int $id, array $jetons, string $titre): void
    {
        $jetons = array_values(array_filter($jetons, static fn (string $j): bool => $j !== ''));
        if ($jetons === []) {
            return;
        }

        $this->groupes[$id] = ['id' => $id, 'jetons' => $jetons, 'titre' => $titre];
        foreach ($jetons as $mot) {
            $this->index[$mot][] = $id;
        }
    }

    /**
     * Le groupe auquel rattacher une dépêche, ou null s'il faut en ouvrir un.
     *
     * @param list<string> $jetons
     */
    public function rapprocher(array $jetons): ?int
    {
        // Sous quatre mots significatifs, un titre ne dit pas assez pour être
        // rapproché sans risque : « Ce que l'on sait de l'incendie » finirait
        // collé à n'importe quel incendie de la journée.
        if (count($jetons) < 4) {
            return null;
        }

        $candidats = [];
        foreach ($jetons as $mot) {
            foreach ($this->index[$mot] ?? [] as $id) {
                $candidats[$id] = ($candidats[$id] ?? 0) + 1;
            }
        }

        $meilleur = null;
        $meilleurScore = $this->seuil;

        foreach ($candidats as $id => $communs) {
            if ($communs < 2) {
                continue;
            }
            $score = Util::jaccard($jetons, $this->groupes[$id]['jetons']);
            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleur = $id;
            }
        }

        return $meilleur;
    }

    /**
     * Ouvre un groupe et le rend immédiatement comparable : deux dépêches
     * arrivées dans le même cycle doivent se rejoindre, pas fonder deux
     * événements distincts.
     *
     * @param list<string> $jetons
     */
    public function ouvrir(string $titre, array $jetons, int $quand, int $lexique, string $motifs): int
    {
        $id = $this->base->creerGroupe($titre, $jetons, $quand, $lexique, $motifs);
        $this->ajouterIndex($id, $jetons, $titre);

        return $id;
    }
}
