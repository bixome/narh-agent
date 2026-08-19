<?php
declare(strict_types=1);

/**
 * Les vecteurs de titres — la reconnaissance d'un même événement sous deux
 * formulations qui ne partagent pas leurs mots.
 *
 * `Regroupeur` compare des ensembles de mots (Jaccard). C'est rapide, c'est
 * reproductible, et ça suffit la plupart du temps. Mais deux rédactions qui
 * couvrent le même incendie n'écrivent pas le même titre : « Un incendie en
 * Lozère a parcouru 250 hectares, deux hameaux évacués » et « Incendies : 180
 * hectares brûlés dans un feu en Lozère, un pompier blessé » ont un Jaccard de
 * 0,15 — très loin du seuil. Mesuré sur la base : **cinq groupes distincts pour
 * un seul incendie**. Or la reprise se compte en maisons et fait le score
 * d'alerte : un événement majeur était noté comme cinq faits mineurs.
 *
 * Le vecteur les rapproche là où les mots échouent. Mesuré sur 120 paires de
 * la base, avec bge-m3 :
 *
 * |            | min  | médiane | max  |
 * |------------|------|---------|------|
 * | même sujet | 0,76 | 0,86    | 0,97 |
 * | sujets ≠   | 0,16 | 0,28    | 0,60 |
 *
 * Un fossé entre 0,60 et 0,76 : le seuil se pose au milieu et n'a rien d'un
 * réglage délicat. C'est ce qui rend la chose admissible sous la règle 4 — la
 * mesure est déterministe et rejouable, comme le lexique, pas un avis.
 *
 * **Hors cycle, et c'est structurel.** Un appel coûte 400 ms d'ouverture quel
 * que soit le nombre de textes : un par dépêche ferait quarante secondes pour
 * un cycle qui en a quinze. En lot, on descend à 70 ms par titre. Le
 * collecteur, lui, traite les dépêches une par une, entrelacées avec ses
 * requêtes HTTP — il n'a jamais le lot sous la main. La réconciliation est
 * donc un passage à part (`php cli.php --fusionner`), ce qui est aussi sa
 * nature : rapprocher des entités se fait sur un ensemble constitué, pas au
 * fil de l'eau.
 *
 * Ne lève jamais : un moteur absent rend un tableau vide, et l'appelant s'en
 * va sans rien fusionner. La collecte n'en dépend pas.
 */
final class Vecteurs
{
    /** La dimension de bge-m3. Vérifiée à la lecture : un modèle changé sans
        que la base soit vidée donnerait des comparaisons entre des espaces
        différents, c'est-à-dire du bruit présenté comme un score. */
    public const DIMENSION = 1024;

    public function __construct(
        private readonly string $url,
        private readonly string $modele,
        private readonly int $lot,
        /* Le modèle ne pèse que 0,62 Gio, mais il partage la carte avec la voix
           (5,76). On le laisse le temps du passage, pas au-delà : une antenne
           qui rouvre ensuite doit retrouver sa VRAM. */
        private readonly int $residence,
    ) {
    }

    public static function depuisReglages(): self
    {
        /** @var array<string, mixed> $r */
        $r = (array) narh_reglage('vecteurs', []);

        return new self(
            (string) (narh_reglage('ollama')['url'] ?? 'http://127.0.0.1:11434'),
            (string) ($r['modele'] ?? 'bge-m3'),
            (int) ($r['lot'] ?? 64),
            (int) ($r['residence'] ?? 120),
        );
    }

    public static function activee(): bool
    {
        return (bool) (narh_reglage('vecteurs', [])['activee'] ?? false);
    }

    public static function seuil(): float
    {
        return (float) (narh_reglage('vecteurs', [])['similarite'] ?? 0.70);
    }

    /**
     * Les vecteurs de plusieurs textes, dans l'ordre reçu.
     *
     * Découpé en lots : un seul appel de mille titres tient la connexion
     * ouverte trop longtemps pour rien, et une coupure perdrait tout le
     * travail plutôt qu'un lot.
     *
     * @param  list<string>       $textes
     * @return list<list<float>>  vide si le moteur n'a pas répondu
     */
    public function embarquer(array $textes): array
    {
        if ($textes === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($textes, max(1, $this->lot)) as $morceau) {
            $lot = $this->appeler($morceau);
            if ($lot === []) {
                return [];   // tout ou rien : un trou décalerait l'alignement
            }
            foreach ($lot as $v) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>      $textes
     * @return list<list<float>>
     */
    private function appeler(array $textes): array
    {
        $ch = curl_init($this->url . '/api/embed');
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => $this->modele,
                'input'      => array_values($textes),
                'keep_alive' => $this->residence,
            ], JSON_INVALID_UTF8_SUBSTITUTE),   // un octet Latin-1 dans un titre perdrait le lot entier
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $corps = curl_exec($ch);
        curl_close($ch);

        if (!is_string($corps) || $corps === '') {
            return [];
        }

        $data = json_decode($corps, true);
        $vecteurs = $data['embeddings'] ?? null;

        if (!is_array($vecteurs) || count($vecteurs) !== count($textes)) {
            return [];
        }

        return array_map(static fn (array $v): array => array_map('floatval', $v), $vecteurs);
    }

    /**
     * Ranger un vecteur en binaire, **normalisé**.
     *
     * Normaliser ici et pas à la comparaison : le passage compare chaque groupe
     * à ses voisins, donc un même vecteur sert des dizaines de fois. Une fois
     * de norme 1, le cosinus n'est plus qu'un produit scalaire — c'est la
     * différence entre une passe qui tient en secondes et une qui n'en finit
     * pas sur neuf cents groupes.
     *
     * `float` de PHP est un double ; on range en simple précision, moitié moins
     * de place pour une décimale qui ne change aucun verdict à ce seuil.
     *
     * @param list<float> $v
     */
    public static function empaqueter(array $v): string
    {
        $norme = 0.0;
        foreach ($v as $x) {
            $norme += $x * $x;
        }
        $norme = sqrt($norme);
        if ($norme <= 0.0) {
            return '';
        }

        $out = '';
        foreach ($v as $x) {
            $out .= pack('g', $x / $norme);   // 'g' : float 32 bits, petit-boutiste — même lecture partout
        }

        return $out;
    }

    /** @return list<float> vide si l'octet ne décrit pas un vecteur du bon espace */
    public static function depaqueter(string $brut): array
    {
        if ($brut === '' || strlen($brut) !== self::DIMENSION * 4) {
            return [];
        }

        /** @var list<float> $v */
        $v = array_values(unpack('g*', $brut) ?: []);

        return $v;
    }

    /**
     * La similarité de deux vecteurs déjà normalisés : un produit scalaire.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function similarite(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }

        $d = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $d += $a[$i] * $b[$i];
        }

        return $d;
    }
}
