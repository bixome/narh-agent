<?php
declare(strict_types=1);

/**
 * Le cycle de collecte.
 *
 * Un cycle = relever les sources échues, analyser ce qui revient, insérer ce
 * qui est nouveau, recalculer les événements touchés. Tout est en un passage :
 * pas de file d'attente, pas de démon obligatoire.
 *
 * Deux façons de le déclencher :
 *
 * - `php cli.php --veille` : une boucle qui appelle cycle() sans fin. C'est le
 *   mode normal — l'écran devient un lecteur, il répond en quelques
 *   millisecondes et le rafraîchissement ne dépend plus du réseau.
 * - Le sondage du navigateur : si aucun cycle n'a tourné depuis la cadence la
 *   plus courte, api.php en lance un lui-même (réglage `collecte_web`). Rien à
 *   installer, au prix d'un sondage plus lent de temps en temps.
 *
 * Le verrou de fichier garantit qu'un seul cycle tourne à la fois. Sans lui,
 * trois onglets ouverts déclencheraient trois collectes simultanées sur les
 * mêmes sources.
 */
final class Collecteur
{
    private const VERROU = NARH_VAR . '/cycle.lock';

    public function __construct(private readonly Base $base)
    {
    }

    /** Un cycle tourne-t-il en ce moment ? */
    public static function occupe(): bool
    {
        $fp = @fopen(self::VERROU, 'c');
        if ($fp === false) {
            return false;
        }
        $libre = flock($fp, LOCK_EX | LOCK_NB);
        if ($libre) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return !$libre;
    }

    /**
     * @param bool $tout    relever toutes les sources, sans regarder la cadence
     * @param int|null $budget secondes au-delà desquelles on ne commence plus de lot
     * @return array{
     *     debut: int, fin: int, ms: int, sources: int, recus: int,
     *     nouveaux: int, alertes: int, erreurs: int, saute: bool
     * }
     */
    public function cycle(bool $tout = false, ?int $budget = null): array
    {
        $debut = microtime(true);
        $maintenant = time();

        $rapport = [
            'debut' => $maintenant, 'fin' => $maintenant, 'ms' => 0,
            'sources' => 0, 'recus' => 0, 'nouveaux' => 0,
            'alertes' => 0, 'erreurs' => 0, 'saute' => false,
        ];

        $verrou = fopen(self::VERROU, 'c');
        if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
            // Un autre cycle tourne : le sien fera le travail. Rendre la main
            // tout de suite vaut mieux qu'attendre — l'écran a déjà des données.
            if ($verrou !== false) {
                fclose($verrou);
            }
            $rapport['saute'] = true;

            return $rapport;
        }

        try {
            $dues = $this->base->sourcesDues($maintenant, $tout);
            if ($dues === []) {
                return $rapport;
            }

            $reponses = $this->relever($dues);
            $rapport['sources'] = count($reponses);

            $regroupeur = new Regroupeur($this->base, $maintenant);
            $touches = [];

            $pdo = $this->base->pdo();
            $pdo->beginTransaction();

            try {
                foreach ($dues as $source) {
                    $id = (string) $source['id'];
                    if (!isset($reponses[$id])) {
                        continue;
                    }

                    $bilan = $this->traiter($source, $reponses[$id], $regroupeur, $maintenant, $touches);
                    $rapport['recus']    += $bilan['recus'];
                    $rapport['nouveaux'] += $bilan['nouveaux'];
                    $rapport['erreurs']  += $bilan['erreur'] ? 1 : 0;

                    if ($budget !== null && microtime(true) - $debut > $budget) {
                        break;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Le recalcul est hors transaction : il touche des lignes que
            // l'écran est en train de lire, et chaque groupe est indépendant.
            foreach (array_keys($touches) as $groupeId) {
                $etat = $this->base->recalculerGroupe((int) $groupeId, $maintenant);
                if ($etat['niveau'] >= Alerte::ALERTE) {
                    $rapport['alertes']++;
                }
            }
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }

        $rapport['fin'] = time();
        $rapport['ms']  = (int) round((microtime(true) - $debut) * 1000);

        $this->base->setMeta('cycle', json_encode($rapport, JSON_THROW_ON_ERROR));

        return $rapport;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return array<string, array<string, mixed>>
     */
    private function relever(array $sources): array
    {
        $requetes = [];
        foreach ($sources as $s) {
            $requetes[(string) $s['id']] = [
                'url'     => (string) $s['url'],
                'etag'    => $s['etag'] !== null ? (string) $s['etag'] : null,
                'modifie' => $s['modifie'] !== null ? (string) $s['modifie'] : null,
            ];
        }

        return (new Http(narh_reglages()))->lot($requetes);
    }

    /**
     * L'instant où une source vient de mourir ou de se rétablir — `null` si
     * son état ne change pas de camp (un échec de plus quand elle est déjà
     * morte n'est pas une nouvelle qui se date).
     *
     * C'est cette écriture, ponctuelle, qui alimente la catégorie « flux » du
     * journal — voir Base::journal(). Ni compteur ni liste, un instant.
     */
    private function transition(int $echecsAvant, int $echecsApres, int $maintenant): ?int
    {
        $morte = (int) narh_reglage('echecs_morte', 6);
        $etaitMorte = $echecsAvant >= $morte;
        $estMorte   = $echecsApres >= $morte;

        return $etaitMorte !== $estMorte ? $maintenant : null;
    }

    /**
     * Une source, une réponse.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $reponse
     * @param array<int, true> $touches groupes à recalculer — passé par référence
     * @return array{recus: int, nouveaux: int, erreur: bool}
     */
    private function traiter(
        array $source,
        array $reponse,
        Regroupeur $regroupeur,
        int $maintenant,
        array &$touches
    ): array {
        $id   = (string) $source['id'];
        $code = (int) $reponse['code'];
        $bilan = ['recus' => 0, 'nouveaux' => 0, 'erreur' => false];
        $echecsAvant = (int) $source['echecs'];

        /* -- 304 : rien n'a changé. Le cas le plus fréquent, et le moins cher. */
        if ($code === 304) {
            $champs = [
                'essai' => $maintenant, 'succes' => $maintenant,
                'code' => 304, 'ms' => (int) $reponse['ms'],
                'etat' => 'inchange', 'erreur' => null, 'echecs' => 0, 'recus' => 0,
            ];
            if (($t = $this->transition($echecsAvant, 0, $maintenant)) !== null) {
                $champs['etat_change_le'] = $t;
            }
            $this->base->majSource($id, $champs);

            return $bilan;
        }

        /* -- Échec réseau ou HTTP. La cadence recule d'elle-même (sourcesDues). */
        if ($reponse['erreur'] !== null || $code < 200 || $code >= 300 || $reponse['corps'] === '') {
            $echecsApres = $echecsAvant + 1;
            $champs = [
                'essai' => $maintenant, 'code' => $code, 'ms' => (int) $reponse['ms'],
                'etat' => 'erreur',
                'erreur' => Util::tronquer((string) ($reponse['erreur'] ?? "HTTP $code"), 120),
                'echecs' => $echecsApres,
            ];
            if (($t = $this->transition($echecsAvant, $echecsApres, $maintenant)) !== null) {
                $champs['etat_change_le'] = $t;
            }
            $this->base->majSource($id, $champs);
            $bilan['erreur'] = true;

            return $bilan;
        }

        $items = Flux::analyser((string) $reponse['corps'], (string) $source['url']);

        if ($items === []) {
            $echecsApres = $echecsAvant + 1;
            $champs = [
                'essai' => $maintenant, 'code' => $code, 'ms' => (int) $reponse['ms'],
                'etat' => 'vide', 'erreur' => 'flux illisible ou sans article',
                'echecs' => $echecsApres,
            ];
            if (($t = $this->transition($echecsAvant, $echecsApres, $maintenant)) !== null) {
                $champs['etat_change_le'] = $t;
            }
            $this->base->majSource($id, $champs);
            $bilan['erreur'] = true;

            return $bilan;
        }

        foreach ($items as $rang => $item) {
            $bilan['recus']++;

            $cle = $id . '|' . sha1(Util::canoniser($item['lien']));

            /* Déjà vue ? On s'arrête là. Ouvrir un groupe avant de savoir si la
               dépêche entre en base laisserait un événement sans article
               derrière chaque doublon — et un flux relu toutes les 90 secondes
               n'apporte qu'une entrée neuve sur vingt. */
            $connu = $this->base->connu($cle);
            if ($connu !== null) {
                if ($connu['titre'] !== $item['titre']) {
                    $this->base->rafraichir($connu['id'], $item['titre'], $item['resume'], $maintenant);
                }
                continue;
            }

            $lexique = Alerte::lexique($item['titre'], $item['resume']);
            $jetons  = Util::jetons($item['titre']);

            /* La date de publication ne sert au tri que si elle est plausible
               (Util::horodatage écarte le futur et l'archive). Certains flux
               n'en donnent aucune — celui du Parisien ne transporte qu'un titre
               et un lien.

               Les dater toutes à l'heure du relevé les ferait arriver en bloc
               en tête d'un fil trié par publication : cent entrées d'un même
               journal devant l'actualité du moment. Reste le seul signal
               disponible, et il est bon : le rang dans le flux. Le rédacteur en
               chef a mis en premier ce qui compte le plus. On recule donc d'un
               cran par position, et on plafonne — au-delà d'une heure et demie,
               l'ordre exact n'a plus d'importance, et sortir de la fenêtre de
               regroupement empêcherait la dépêche de rejoindre son événement. */
            $publie  = $item['publie'];
            $dateTri = $publie ?? $maintenant - min((int) $rang * 120, 5400);

            $groupeId = $regroupeur->rapprocher($jetons);
            if ($groupeId === null) {
                $groupeId = $regroupeur->ouvrir(
                    $item['titre'],
                    $jetons,
                    $dateTri,
                    $lexique['score'],
                    implode(', ', $lexique['motifs'])
                );
            }

            $nouveau = $this->base->inserer([
                'cle'       => $cle,
                'source_id' => $id,
                'groupe_id' => $groupeId,
                'titre'     => $item['titre'],
                'lien'      => $item['lien'],
                'resume'    => $item['resume'],
                'publie'    => $publie,
                'vu'        => $maintenant,
                'date_tri'  => $dateTri,
                'maj'       => $maintenant,
                'lexique'   => $lexique['score'],
                'score'     => $lexique['score'],
                'niveau'    => Alerte::niveau($lexique['score']),
                'motifs'    => implode(', ', $lexique['motifs']),
                'jetons'    => implode(' ', $jetons),
            ]);

            if ($nouveau !== null) {
                $bilan['nouveaux']++;
                $touches[$groupeId] = true;
            }
        }

        $champs = [
            'essai' => $maintenant, 'succes' => $maintenant,
            'code' => $code, 'ms' => (int) $reponse['ms'],
            'etat' => 'ok', 'erreur' => null, 'echecs' => 0,
            'etag' => $reponse['etag'], 'modifie' => $reponse['modifie'],
            'recus' => $bilan['recus'],
            'total' => (int) $source['total'] + $bilan['nouveaux'],
        ];
        if (($t = $this->transition($echecsAvant, 0, $maintenant)) !== null) {
            $champs['etat_change_le'] = $t;
        }
        $this->base->majSource($id, $champs);

        return $bilan;
    }

    /**
     * Le cycle est-il assez vieux pour qu'un sondage le relance lui-même ?
     * On prend la cadence la plus courte du parc : au-delà, une source au moins
     * a quelque chose à dire.
     */
    public function perime(int $maintenant): bool
    {
        $cycle = $this->base->cycle();
        $pdo = $this->base->pdo();
        $mini = (int) $pdo->query('SELECT COALESCE(MIN(cadence), 90) FROM source WHERE actif = 1')->fetchColumn();

        return (int) ($cycle['fin'] ?? 0) + max(30, $mini) <= $maintenant;
    }
}
