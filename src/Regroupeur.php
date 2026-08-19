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

    /* ---- La réconciliation, après coup --------------------------------------
       Ce que les mots n'ont pas su réunir, les vecteurs le peuvent. Séparé de
       `rapprocher()` et non fondu dedans, pour deux raisons qui tiennent
       chacune seule :

       — Le coût. Un appel d'embedding demande 400 ms d'ouverture quel que soit
         le nombre de textes. Le collecteur traite les dépêches une par une,
         entrelacées avec ses requêtes HTTP : en ligne, ce serait quarante
         secondes pour un cycle qui en a quinze. En lot, 70 ms par titre.

       — La règle 4. La collecte ne pense pas : elle reste lexicale, donc
         rapide et rejouable sans moteur. La réconciliation est un second
         passage, qu'on lance quand on veut, et dont rien ne dépend. */

    /**
     * Réunir les groupes que le Jaccard a laissés séparés.
     *
     * Mesuré sur la base : cinq groupes distincts pour un même incendie en
     * Lozère, trois pour un même transfert, trois pour une même rencontre
     * Trump–Kim. Comme la reprise se compte en maisons et fait le score, un
     * événement majeur s'y présentait comme une poignée de faits mineurs.
     *
     * @return array{groupes: int, calcules: int, fusions: int, absorbes: list<array{garde: int, absorbe: int, similarite: float, titre: string}>}
     */
    public static function reconcilier(Base $base, int $maintenant, bool $simuler = false): array
    {
        $bilan = ['groupes' => 0, 'calcules' => 0, 'fusions' => 0, 'absorbes' => []];

        $fenetre = (int) narh_reglage('fenetre', 10800);
        $groupes = $base->groupesAReconcilier($maintenant - $fenetre);
        $bilan['groupes'] = count($groupes);
        if (count($groupes) < 2) {
            return $bilan;
        }

        /* Calculer ce qui manque, en une fois. Un groupe déjà vectorisé ne
           repaie pas : c'est ce qui rend le passage tenable en le répétant. */
        $aCalculer = [];
        foreach ($groupes as $i => $g) {
            if ($g['vecteur'] === '') {
                $aCalculer[$i] = (string) $g['titre'];
            }
        }

        if ($aCalculer !== []) {
            $vecteurs = Vecteurs::depuisReglages()->embarquer(array_values($aCalculer));
            if ($vecteurs === []) {
                // Moteur absent ou muet : on ne fusionne rien plutôt que de
                // fusionner sur les seuls groupes déjà vectorisés, ce qui
                // rendrait le passage dépendant de son historique.
                Journal::noter('warn', 'veille', 'réconciliation : le moteur de vecteurs n’a pas répondu');

                return $bilan;
            }

            foreach (array_keys($aCalculer) as $rang => $i) {
                $paquet = Vecteurs::empaqueter($vecteurs[$rang]);
                $groupes[$i]['vecteur'] = $paquet;
                if (!$simuler && $paquet !== '') {
                    $base->poserVecteur((int) $groupes[$i]['id'], $paquet, (string) $groupes[$i]['titre']);
                }
            }
            $bilan['calcules'] = count($aCalculer);
        }

        /* Le même index inversé que le regroupement en ligne : deux groupes ne
           se comparent que s'ils partagent au moins deux mots. Sans lui, neuf
           cents groupes feraient quatre cent mille comparaisons de vecteurs de
           mille vingt-quatre dimensions. Aucune fusion utile n'y échappe — les
           paires manquées mesurées partagent toutes des mots, c'est leur
           **proportion** qui était trop faible, pas leur nombre. */
        $index = [];
        $decodes = [];
        foreach ($groupes as $i => $g) {
            $decodes[$i] = Vecteurs::depaqueter((string) $g['vecteur']);
            foreach (array_filter(explode(' ', (string) $g['jetons'])) as $mot) {
                $index[$mot][] = $i;
            }
        }

        $seuil = Vecteurs::seuil();

        /* Union-find : un incendie couvert par cinq rédactions forme une
           chaîne, pas des paires indépendantes. Fusionner deux à deux dans
           l'ordre d'apparition donnerait deux groupes résiduels là où il n'y a
           qu'un événement. */
        $chef = range(0, count($groupes) - 1);
        $trouver = static function (int $x) use (&$chef, &$trouver): int {
            while ($chef[$x] !== $x) {
                $chef[$x] = $chef[$chef[$x]];
                $x = $chef[$x];
            }

            return $x;
        };

        $vus = [];
        foreach ($groupes as $i => $g) {
            if ($decodes[$i] === []) {
                continue;
            }

            $candidats = [];
            foreach (array_filter(explode(' ', (string) $g['jetons'])) as $mot) {
                foreach ($index[$mot] ?? [] as $j) {
                    if ($j > $i) {
                        $candidats[$j] = ($candidats[$j] ?? 0) + 1;
                    }
                }
            }

            foreach ($candidats as $j => $communs) {
                if ($communs < 2 || $decodes[$j] === []) {
                    continue;
                }
                $paire = $i . ':' . $j;
                if (isset($vus[$paire])) {
                    continue;
                }
                $vus[$paire] = true;

                /* Proches dans le temps, sinon ce n'est pas le même événement.
                   Le projet le dit déjà pour le regroupement en ligne — deux
                   dépêches ne décrivent le même fait que publiées dans le même
                   intervalle — mais la réconciliation, elle, examinait tous les
                   groupes actifs sans exiger qu'ils soient proches **l'un de
                   l'autre**. Mesuré : « Philadelphia 76ers – Indiana Pacers, le
                   15-11-2026 » et « Philadelphia 76ers – Brooklyn Nets, le
                   15-01-2027 » se ressemblent à 0,81, parce qu'un vecteur voit
                   un gabarit d'annonce de match et non la date qui les sépare.
                   Deux mois d'écart : le calendrier tranche là où la langue ne
                   distingue rien. */
                if (
                    self::ecartes($groupes[$i], $groupes[$j], $fenetre)
                    || self::datesDivergentes((string) $g['titre'], (string) $groupes[$j]['titre'])
                ) {
                    continue;
                }

                $s = Vecteurs::similarite($decodes[$i], $decodes[$j]);
                if ($s < $seuil) {
                    continue;
                }
                if ($trouver($i) === $trouver($j)) {
                    continue;
                }

                $bilan['absorbes'][] = [
                    'garde'      => (int) $groupes[$i]['id'],
                    'absorbe'    => (int) $groupes[$j]['id'],
                    'similarite' => round($s, 3),
                    'titre'      => (string) $groupes[$j]['titre'],
                    'jaccard'    => round(Util::jaccard(
                        explode(' ', (string) $g['jetons']),
                        explode(' ', (string) $groupes[$j]['jetons']),
                    ), 3),
                ];
                $chef[$trouver($j)] = $trouver($i);
            }
        }

        if ($simuler) {
            $bilan['fusions'] = count($bilan['absorbes']);

            return $bilan;
        }

        /* On verse dans le plus ancien : l'identifiant croît avec le temps, et
           c'est le premier groupe ouvert qui porte le début du sujet. */
        foreach ($bilan['absorbes'] as $f) {
            [$garde, $absorbe] = $f['garde'] < $f['absorbe']
                ? [$f['garde'], $f['absorbe']]
                : [$f['absorbe'], $f['garde']];

            $base->fusionnerGroupes($garde, $absorbe, $maintenant);
            $bilan['fusions']++;
        }

        if ($bilan['fusions'] > 0) {
            Journal::noter('ok', 'veille', sprintf(
                'réconciliation : %d groupe(s) réunis sur %d examinés',
                $bilan['fusions'],
                $bilan['groupes'],
            ));
        }

        return $bilan;
    }

    /**
     * Deux titres qui nomment chacun une date, et pas la même.
     *
     * Le garde temporel ne suffit pas, et la mesure a dit pourquoi : `premier`
     * et `dernier` sont des heures de **collecte**. Une source qui publie son
     * calendrier sportif dépose vingt annonces dans la même minute, et les
     * matchs du 15-11-2026 et du 15-01-2027 se retrouvent à zéro seconde
     * d'écart. La seule chose qui les sépare est écrite dans le titre — et un
     * vecteur n'y voit qu'un gabarit d'annonce de match, à 0,81 de similarité.
     *
     * On ne refuse que lorsque les **deux** titres datent explicitement : un
     * titre daté et un titre qui ne l'est pas peuvent parfaitement couvrir le
     * même fait. Et seulement sur des dates écrites en chiffres, ce qui laisse
     * hors de portée les nombres qui ne datent rien — « 250 hectares » contre
     * « 180 hectares » désigne bien le même incendie, et doit le rester.
     */
    private static function datesDivergentes(string $a, string $b): bool
    {
        $dates = static function (string $t): array {
            preg_match_all('#\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})\b#u', $t, $m, PREG_SET_ORDER);

            return array_values(array_unique(array_map(
                static fn (array $d): string => sprintf('%04d-%02d-%02d', (int) $d[3], (int) $d[2], (int) $d[1]),
                $m,
            )));
        };

        $da = $dates($a);
        $db = $dates($b);

        return $da !== [] && $db !== [] && array_intersect($da, $db) === [];
    }

    /**
     * Deux groupes sont-ils trop éloignés dans le temps pour être le même fait ?
     *
     * On compare les **intervalles** [premier, dernier], pas deux instants : un
     * sujet qui dure trois heures et un autre qui commence à la fin du premier
     * se touchent, et doivent pouvoir se rejoindre. Ce n'est que lorsque les
     * deux intervalles ne se recouvrent pas, et qu'il reste plus d'une fenêtre
     * entre eux, qu'on refuse.
     *
     * @param array{premier: int, dernier: int} $a
     * @param array{premier: int, dernier: int} $b
     */
    private static function ecartes(array $a, array $b, int $fenetre): bool
    {
        $debut = max((int) $a['premier'], (int) $b['premier']);
        $fin   = min((int) $a['dernier'], (int) $b['dernier']);

        return ($debut - $fin) > $fenetre;
    }
}
