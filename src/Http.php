<?php
declare(strict_types=1);

/**
 * Le transport.
 *
 * Une seule méthode : un lot de requêtes menées en parallèle par curl_multi.
 * C'est ce qui rend le cycle court — quarante flux relevés en séquence
 * prendraient une minute, en parallèle ils tiennent en deux secondes.
 *
 * Deux économies portent le rafraîchissement rapide :
 *
 * - **Requête conditionnelle.** L'ETag et le Last-Modified du relevé précédent
 *   repartent en If-None-Match / If-Modified-Since. Une source inchangée répond
 *   304, sans corps : interroger toutes les 60 s ne coûte alors qu'un aller-retour.
 * - **Compression.** CURLOPT_ENCODING vide annonce tous les formats gérés par
 *   la libcurl locale et décompresse à l'arrivée.
 */
final class Http
{
    /** @param array<string, mixed> $reglages */
    public function __construct(private readonly array $reglages)
    {
    }

    /**
     * @param array<string, array{url: string, etag?: ?string, modifie?: ?string}> $requetes
     * @return array<string, array{
     *     code: int, corps: string, etag: ?string, modifie: ?string,
     *     ms: int, erreur: ?string, url: string
     * }>
     */
    public function lot(array $requetes): array
    {
        if ($requetes === []) {
            return [];
        }

        $multi = curl_multi_init();
        // Cinquante poignées de main TLS lancées d'un coup saturent le résolveur
        // avant de saturer la ligne : curl met les suivantes en file d'attente.
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, (int) ($this->reglages['parallele'] ?? 16));

        $handles = [];
        $entetes = [];

        foreach ($requetes as $id => $requete) {
            $ch = curl_init();
            $entetes[$id] = ['etag' => null, 'modifie' => null];

            $envoi = [
                'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.8',
                'Accept-Language: fr-FR,fr;q=0.9',
                'Cache-Control: no-cache',
            ];
            if (!empty($requete['etag'])) {
                $envoi[] = 'If-None-Match: ' . $requete['etag'];
            }
            if (!empty($requete['modifie'])) {
                $envoi[] = 'If-Modified-Since: ' . $requete['modifie'];
            }

            curl_setopt_array($ch, [
                CURLOPT_URL            => $requete['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_TIMEOUT        => (int) ($this->reglages['timeout'] ?? 8),
                CURLOPT_CONNECTTIMEOUT => (int) ($this->reglages['connexion'] ?? 4),
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => (string) ($this->reglages['agent'] ?? 'NARH'),
                CURLOPT_HTTPHEADER     => $envoi,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // Un flux d'actualité pèse quelques dizaines de kilo-octets. Au-delà
                // du plafond, la réponse n'est pas un flux : couper au transfert
                // plutôt que de charger la mémoire pour rien.
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, int $attendu, int $recu): int {
                    return $recu > (int) ($this->reglages['taille_max'] ?? 4194304) ? 1 : 0;
                },
                CURLOPT_HEADERFUNCTION => static function ($ch, string $ligne) use (&$entetes, $id): int {
                    $long = strlen($ligne);
                    $deux = explode(':', $ligne, 2);
                    if (count($deux) === 2) {
                        $nom = strtolower(trim($deux[0]));
                        if ($nom === 'etag') {
                            $entetes[$id]['etag'] = trim($deux[1]);
                        } elseif ($nom === 'last-modified') {
                            $entetes[$id]['modifie'] = trim($deux[1]);
                        }
                    }

                    return $long;
                },
            ]);

            $handles[$id] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        /* La boucle est pilotée par les messages « terminé », pas par le nombre
           de transferts actifs.

           Sortir sur `$actives === 0` paraît naturel et donne un lot incomplet :
           quand la file d'attente retient des transferts qui n'ont pas encore
           démarré, le compteur passe à zéro entre deux départs, la boucle rend
           la main, et les poignées jamais lancées ressortent avec un code 0 et
           un errno 0 — un échec silencieux, impossible à distinguer d'un flux
           mort. Compter les CURLMSG_DONE lève l'ambiguïté : on attend d'en avoir
           autant que de requêtes. Le garde-fou horaire évite qu'une libcurl qui
           ne rend jamais son message ne bloque le cycle. */

        $restants = count($handles);
        $limite = microtime(true) + (int) ($this->reglages['timeout'] ?? 8) + 10;

        while ($restants > 0 && microtime(true) < $limite) {
            $etat = curl_multi_exec($multi, $actives);
            if ($etat !== CURLM_OK) {
                break;
            }

            while (($message = curl_multi_info_read($multi)) !== false) {
                if ($message['msg'] === CURLMSG_DONE) {
                    $restants--;
                }
            }
            if ($restants <= 0) {
                break;
            }

            // select() rend -1 quand il n'y a aucun descripteur à surveiller —
            // pendant une résolution DNS, ou tant que la file n'a rien lancé.
            // Sans la pause, la boucle brûlerait un cœur à vide.
            if ($actives > 0) {
                if (curl_multi_select($multi, 0.5) === -1) {
                    usleep(2000);
                }
            } else {
                usleep(2000);
            }
        }

        $reponses = [];
        foreach ($handles as $id => $ch) {
            $corps  = curl_multi_getcontent($ch) ?? '';
            $erreur = curl_error($ch);
            $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($erreur === '' && $code === 0) {
                $erreur = 'transfert non abouti (lot interrompu)';
            }

            $reponses[$id] = [
                'code'    => $code,
                'corps'   => is_string($corps) ? $corps : '',
                'etag'    => $entetes[$id]['etag'],
                'modifie' => $entetes[$id]['modifie'],
                'ms'      => (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
                'erreur'  => $erreur !== '' ? $erreur : null,
                'url'     => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            ];

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $reponses;
    }
}
