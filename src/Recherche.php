<?php
declare(strict_types=1);

/**
 * La recherche sur le web ouvert, par un métamoteur qu'on héberge.
 *
 * Ce qui manquait vraiment à NARH. Il voit dix-huit mille dépêches, mais
 * seulement celles que ses cinquante-neuf flux lui apportent : un sujet
 * qu'aucune de ses sources ne couvre n'existe pas pour lui, et il ne peut même
 * pas s'en rendre compte. Interrogé sur ses capacités, il répondait « je ne
 * peux pas naviguer sur Internet » — c'était exact.
 *
 * **SearXNG, et pas une API commerciale.** Le projet ne fait aucune requête
 * vers l'extérieur depuis le navigateur, et récupère le texte côté serveur pour
 * ne pas charger les traceurs d'un tiers dans l'écran. Confier les requêtes de
 * la rédaction à un moteur commercial, avec une clé qui les identifie, aurait
 * contredit cette posture au moment même où l'on ouvre la porte sur le web.
 * SearXNG tourne sur la machine, agrège les moteurs, et ne garde rien.
 *
 * **Ce n'est pas une source, c'est une piste.** Ce qui revient d'ici n'entre
 * pas dans la veille et ne compte pour aucune reprise (règle 4) : la collecte
 * reste faite de flux qu'on a choisis, dont on connaît la maison et le rang.
 * Un résultat de recherche est un titre et un lien trouvés on ne sait trop où,
 * et le modèle doit le présenter comme tel. Pour en tirer autre chose qu'un
 * titre, il faut le lire — et c'est `Lecture` qui s'en charge, avec ses gardes.
 *
 * Éteinte tant qu'aucun point d'accès n'est déclaré : une fonction qui appelle
 * un service absent doit le dire, pas échouer.
 */
final class Recherche
{
    public static function activee(): bool
    {
        return self::point() !== '';
    }

    private static function point(): string
    {
        return trim((string) (narh_reglage('recherche', [])['point'] ?? ''));
    }

    /**
     * Ce que le web ouvert dit d'un sujet.
     *
     * Rend un tableau vide dès que quoi que ce soit cloche — service éteint,
     * injoignable, réponse illisible. L'appelant dira « aucun résultat », ce
     * qui est vrai dans tous ces cas et ne demande pas au modèle de deviner
     * lequel.
     *
     * @return list<array{titre: string, lien: string, extrait: string, moteur: string}>
     */
    public static function chercher(string $requete, int $limite = 5): array
    {
        $requete = trim($requete);
        if ($requete === '' || !self::activee()) {
            return [];
        }

        $limite = max(1, min($limite, 10));
        $reglages = (array) narh_reglage('recherche', []);

        $corps = Lecture::service(
            self::point(),
            [
                'q'      => $requete,
                'format' => 'json',
                // La langue oriente le classement du métamoteur : sans elle,
                // une requête française remonte surtout des pages anglaises.
                'language' => (string) ($reglages['langue'] ?? 'fr'),
            ],
            (int) ($reglages['timeout'] ?? 8),
        );

        if ($corps === null) {
            Journal::noter('warn', 'agent', 'recherche web : le métamoteur n’a pas répondu');

            return [];
        }

        $data = json_decode($corps, true);
        if (!is_array($data) || !is_array($data['results'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($data['results'] as $r) {
            $lien = (string) ($r['url'] ?? '');
            $titre = trim((string) ($r['title'] ?? ''));
            if ($titre === '' || $lien === '') {
                continue;
            }

            /* Le même garde que pour une lecture. SearXNG agrège des moteurs
               dont on ne contrôle pas l'index : rien n'interdit qu'un résultat
               pointe une adresse interne, et le lien finira sous le doigt de
               quelqu'un. On écarte ici plutôt qu'au clic. */
            if (!Lecture::adresseSure($lien)) {
                continue;
            }

            $out[] = [
                'titre'   => Util::tronquer($titre, 160),
                'lien'    => $lien,
                'extrait' => Util::tronquer(trim((string) ($r['content'] ?? '')), 300),
                // De quel moteur vient le résultat : c'est l'équivalent de la
                // maison pour une dépêche, et cela permet de l'attribuer.
                'moteur'  => Util::tronquer((string) ($r['engine'] ?? ''), 30),
            ];

            if (count($out) >= $limite) {
                break;
            }
        }

        return $out;
    }
}
