<?php
declare(strict_types=1);

/**
 * Les outils exposés au modèle par function-calling.
 *
 * Chaque outil est une fonction pure : arguments validés → résultat
 * sérialisable. Portés depuis otow-agent, avec un changement de fond : là où
 * otow-agent lisait la base d'un projet voisin en lecture seule, NARH tient sa
 * propre veille — `rechercher_actualites` interroge `Base`, la même classe que
 * l'écran Veille, pas un fichier externe (CLAUDE.md, règle 4 : la collecte ne
 * pense pas, mais rien n'interdit à l'agent de la lire).
 *
 * Le bac à sable fichiers est borné à `var/bac`, sans sortie possible.
 */
final class Outils
{
    public const SANDBOX = NARH_VAR . '/bac';

    /** @return list<array{type: string, function: array}> */
    public static function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'heure_actuelle',
                    'description' => "Donne la date et l'heure actuelles du serveur.",
                    'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'calculer',
                    'description' => 'Évalue une expression arithmétique (+ - * / parenthèses uniquement).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['expression' => ['type' => 'string', 'description' => 'Ex: (4 + 2) * 3']],
                        'required' => ['expression'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lister_fichiers',
                    'description' => "Liste les fichiers du bac à sable de l'agent.",
                    'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lire_fichier',
                    'description' => "Lit le contenu d'un fichier du bac à sable.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['chemin' => ['type' => 'string', 'description' => 'Nom du fichier, ex: notes.txt']],
                        'required' => ['chemin'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'rechercher_actualites',
                    'description' => "Cherche dans la veille d'actualité tenue par NARH (titres et résumés collectés "
                        . 'en continu). Utile pour toute question sur une actualité récente ou un sujet précis.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'requete' => ['type' => 'string', 'description' => 'Mots-clés à chercher dans le titre ou le résumé'],
                            'limite'  => ['type' => 'integer', 'description' => 'Nombre de dépêches, 5 par défaut, 20 maximum'],
                        ],
                        'required' => ['requete'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Ce qu'il faut savoir d'un outil pour l'afficher et le lancer à la main.
     *
     * Dérivé des définitions, jamais recopié : le schéma qui décrit l'outil au
     * modèle est le même qui dessine son formulaire. Deux tables auraient
     * divergé au premier paramètre ajouté, et le formulaire aurait proposé un
     * champ que l'outil n'accepte plus.
     *
     * @return array{nom: string, description: string, champ: ?string, aide: string, requis: bool}|null
     */
    public static function metadonnees(string $nom): ?array
    {
        foreach (self::definitions() as $def) {
            if (($def['function']['name'] ?? '') !== $nom) {
                continue;
            }

            $f = $def['function'];
            $props = $f['parameters']['properties'] ?? new stdClass();
            $props = is_array($props) ? $props : [];
            $premier = array_key_first($props);

            return [
                'nom'         => $nom,
                'description' => (string) $f['description'],
                // Un seul champ : tous les outils d'ici n'en demandent qu'un
                // (la limite a un défaut). Le jour où l'un en demande deux, ce
                // sera ici qu'on le verra manquer.
                'champ'       => $premier,
                'aide'        => $premier !== null ? (string) ($props[$premier]['description'] ?? '') : '',
                'requis'      => in_array((string) $premier, $f['parameters']['required'] ?? [], true),
            ];
        }

        return null;
    }

    /** @return list<string> les noms, dans l'ordre des définitions */
    public static function noms(): array
    {
        return array_map(
            static fn (array $d): string => (string) $d['function']['name'],
            self::definitions(),
        );
    }

    /**
     * La tuile qui montre ce qu'un outil a consulté, s'il y en a une.
     *
     * Seule la recherche d'actualités a une contrepartie visuelle : un calcul
     * ou une heure n'ont rien à montrer qu'une ligne ne dise déjà. Rendre une
     * tuile pour eux serait un cadre autour d'un mot.
     */
    public static function tuilePour(string $nom, array $arguments): ?Tuile
    {
        if ($nom !== 'rechercher_actualites' || trim((string) ($arguments['requete'] ?? '')) === '') {
            return null;
        }

        return new Tuile(Tuile::VEILLE, ['q' => (string) $arguments['requete'], 'limite' => 8]);
    }

    /** @return array{ok: bool, resultat: mixed} */
    public static function executer(string $nom, array $arguments): array
    {
        try {
            $sortie = match ($nom) {
                'heure_actuelle'   => ['ok' => true, 'resultat' => date('Y-m-d H:i:s')],
                'calculer'         => ['ok' => true, 'resultat' => self::calculer((string) ($arguments['expression'] ?? ''))],
                'lister_fichiers'  => ['ok' => true, 'resultat' => self::listerFichiers()],
                'lire_fichier'     => ['ok' => true, 'resultat' => self::lireFichier((string) ($arguments['chemin'] ?? ''))],
                'rechercher_actualites' => ['ok' => true, 'resultat' => self::rechercherActualites(
                    (string) ($arguments['requete'] ?? ''),
                    (int) ($arguments['limite'] ?? 5),
                )],
                default => ['ok' => false, 'resultat' => "Outil inconnu : $nom"],
            };

            // « Rien trouvé » doit se dire, pas se déduire : un tableau vide
            // laisse le modèle combler le vide de lui-même — mesuré sur
            // otow-agent, il en tirait des réponses inventées.
            if ($sortie['ok'] && $sortie['resultat'] === []) {
                $sortie['resultat'] = 'Aucun résultat.';
            }

            return $sortie;
        } catch (Throwable $e) {
            return ['ok' => false, 'resultat' => $e->getMessage()];
        }
    }

    /**
     * Le résultat d'un outil, mis en mots pour la conversation.
     *
     * Ce qu'un outil rend est structuré ; ce que lit le modèle est du texte.
     * Verser le JSON brut dans le contexte lui fait avaler des guillemets
     * échappés et des URL de 400 caractères — mesuré chez otow-agent, cinq
     * appels suffisaient à faire perdre le fil à llama3.2:3b. Le JSON complet
     * reste dans l'étape affichée à l'inspecteur.
     */
    public static function resumer(string $nom, mixed $resultat): string
    {
        if (is_scalar($resultat)) {
            return (string) $resultat;
        }
        if (!is_array($resultat) || $resultat === []) {
            return 'Aucun résultat.';
        }

        if ($nom === 'rechercher_actualites') {
            $lignes = [];
            foreach (array_slice($resultat, 0, 10) as $a) {
                $lignes[] = sprintf(
                    "- %s (%s, %s)\n  « %s »",
                    $a['titre'] ?? '?',
                    $a['source_nom'] ?? '?',
                    isset($a['date_tri']) ? date('d/m H:i', (int) $a['date_tri']) : '?',
                    mb_strimwidth((string) ($a['resume'] ?? ''), 0, 300, '…'),
                );
            }

            return count($resultat) . " dépêches :\n" . implode("\n", $lignes);
        }

        if ($nom === 'lister_fichiers') {
            $lignes = array_map(
                static fn (array $f): string => sprintf('- %s (%d o)', $f['nom'] ?? '?', $f['taille'] ?? 0),
                $resultat,
            );

            return count($resultat) . " fichiers :\n" . implode("\n", $lignes);
        }

        return (string) json_encode($resultat, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function calculer(string $expression): string
    {
        $expression = trim($expression);
        if ($expression === '' || !preg_match('/^[0-9+\-*\/().\s]+$/', $expression)) {
            throw new InvalidArgumentException('Expression invalide : caractères non autorisés.');
        }
        if (preg_match('/\/\s*0(?!\d)/', $expression)) {
            throw new InvalidArgumentException('Division par zéro.');
        }

        // La liste blanche ci-dessus n'autorise ni lettre, ni `$`, ni guillemet :
        // aucun appel de fonction n'est formulable, l'évaluation reste arithmétique.
        try {
            $resultat = eval("return $expression;");
        } catch (DivisionByZeroError) {
            throw new InvalidArgumentException('Division par zéro.');
        } catch (Throwable) {
            throw new InvalidArgumentException('Expression arithmétique mal formée.');
        }

        if (!is_int($resultat) && !is_float($resultat)) {
            throw new InvalidArgumentException('Expression non numérique.');
        }
        if (is_float($resultat) && !is_finite($resultat)) {
            throw new InvalidArgumentException('Résultat hors limites.');
        }

        return (string) $resultat;
    }

    /** @return list<array{nom: string, taille: int}> */
    private static function listerFichiers(): array
    {
        if (!is_dir(self::SANDBOX)) {
            return [];
        }

        $fichiers = [];
        foreach (scandir(self::SANDBOX) as $entree) {
            $chemin = self::SANDBOX . '/' . $entree;
            if ($entree[0] === '.' || !is_file($chemin)) {
                continue;
            }
            $fichiers[] = ['nom' => $entree, 'taille' => filesize($chemin)];
        }

        return $fichiers;
    }

    private static function lireFichier(string $chemin): string
    {
        $chemin = trim($chemin);
        if ($chemin === '') {
            throw new InvalidArgumentException('Aucun fichier indiqué.');
        }

        $racine = realpath(self::SANDBOX);
        $cible = realpath(self::SANDBOX . '/' . $chemin);

        // Le séparateur final n'est pas une coquetterie : comparer les chemins
        // nus laisserait « ../bacX/secret.txt » passer pour un fichier du bac,
        // puisque « …/bacX » commence bien par « …/bac ».
        if ($racine === false || $cible === false || !str_starts_with($cible, $racine . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Fichier hors du bac à sable ou introuvable.');
        }
        if (!is_file($cible)) {
            throw new InvalidArgumentException("Ce chemin n'est pas un fichier.");
        }
        if (filesize($cible) > 20_000) {
            throw new InvalidArgumentException('Fichier trop volumineux (> 20 Ko).');
        }

        return (string) file_get_contents($cible);
    }

    /**
     * La veille de NARH, cherchée par mots.
     *
     * Le modèle envoie des phrases, pas des mots-clés — `Base::flux()` avec
     * `q` cherche la phrase entière et rate tout au singulier ou au pluriel
     * près. On passe donc par les mêmes conditions que l'écran (rubrique,
     * niveau) mais avec une recherche par mots qu'`Alerte`/`Base` n'offrent
     * pas encore : chaque mot vaut un point, on classe par score.
     */
    private static function rechercherActualites(string $requete, int $limite): array
    {
        $requete = trim($requete);
        if ($requete === '') {
            throw new InvalidArgumentException('Requête vide.');
        }
        $limite = max(1, min($limite, 20));

        /* Les lignes sont rendues telles que la base les donne, sans les
           réduire : l'identifiant et la date brute permettront à l'écran de
           reposer ces dépêches avec la ligne du fil, et de renvoyer à leur
           place dans la veille (le pont, P3). `resumer()` en tire le texte que
           lit le modèle — deux besoins, une seule source. */
        $base = new Base((string) narh_reglage('base_veille'));

        return $base->chercherParMots($requete, $limite);
    }
}
