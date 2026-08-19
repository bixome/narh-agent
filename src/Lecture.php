<?php
declare(strict_types=1);

/**
 * Aller chercher un article et en tirer du texte.
 *
 * **Le seul point de NARH qui sorte vers le réseau** en dehors du collecteur.
 * Les gardes vivent donc ici, une fois pour toutes (CLAUDE.md, règles du
 * projet) — le lecteur (`api/lecteur.php`), l'ingestion (`cli.php --ingerer`)
 * et la vérification des liens (`api/liens.php`) passent tous par elle. Trois
 * copies auraient divergé au premier motif d'habillage ajouté d'un seul côté.
 *
 * Le texte est récupéré **côté serveur** et seuls ses paragraphes sont rendus.
 * Encadrer la page d'origine aurait été plus simple, mais aurait chargé ses
 * publicités et ses traceurs dans l'écran — à rebours de la posture du projet,
 * qui ne fait aucune requête vers l'extérieur depuis le navigateur.
 *
 * Porté depuis otow-agent, où ces règles n'étaient écrites que dans cet en-tête,
 * le projet n'ayant ni README ni CLAUDE.md.
 */
final class Lecture
{
    /** Au-delà, on coupe : un document interminable ne doit bloquer personne. */
    private const TAILLE_MAX = 1_000_000;

    /** Quatre sauts au plus : au-delà, c'est une boucle, pas une redirection. */
    private const SAUTS_MAX = 4;

    /**
     * Une adresse joignable sans risque : http(s), hôte public, résolvable.
     *
     * Les plages privées ne sont pas seulement inutiles ici — c'est par elles
     * qu'une requête sortante devient une requête vers l'intérieur du réseau.
     */
    public static function adresseSure(string $url): bool
    {
        $p = parse_url($url);
        if (!in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '') {
            return false;
        }

        $ip = filter_var($p['host'], FILTER_VALIDATE_IP) ? $p['host'] : gethostbyname($p['host']);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Interroger un service **déclaré dans les réglages**, et rien d'autre.
     *
     * `adresseSure()` refuse les plages privées, et elle a raison : elle garde
     * une URL qui vient d'un flux, d'un modèle, d'une redirection — c'est-à-dire
     * de l'extérieur. Une requête sortante y devient une requête vers
     * l'intérieur du réseau, et c'est par là qu'on lit une console
     * d'administration depuis le web.
     *
     * Un métamoteur qu'on héberge soi-même est le cas exactement inverse, et
     * c'est pourquoi il passe par une porte séparée plutôt que par un
     * assouplissement de l'autre : l'hôte vient de `config/reglages.php`, jamais
     * d'une réponse. Le modèle ne fournit que des **paramètres**, encodés ici.
     * Il ne peut donc ni choisir la machine, ni le port, ni le chemin — la seule
     * chose qu'il gouverne est le texte d'une requête.
     *
     * Aucune redirection n'est suivie : un service local qui redirige ailleurs
     * n'est plus le service qu'on a déclaré.
     *
     * @param  array<string, string|int> $parametres
     */
    public static function service(string $base, array $parametres, int $timeout = 8): ?string
    {
        $p = parse_url($base);
        if (!in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '') {
            return null;
        }

        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($parametres);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_FOLLOWLOCATION   => false,
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_CONNECTTIMEOUT   => 3,
            CURLOPT_USERAGENT        => 'NARH/' . NARH_VERSION,
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $recu) => $recu > self::TAILLE_MAX ? 1 : 0,
        ]);
        $corps = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return (is_string($corps) && $corps !== '' && $code === 200) ? $corps : null;
    }

    /**
     * Le document, redirections suivies à la main.
     *
     * `FOLLOWLOCATION` aurait obéi sans broncher à un « Location: http://10.0.0.1/ » :
     * on valide donc chaque saut plutôt que de déléguer à cURL.
     *
     * @return array{html: ?string, url: string, code: int}
     */
    public static function recuperer(string $url): array
    {
        for ($saut = 0; $saut < self::SAUTS_MAX; $saut++) {
            if (!self::adresseSure($url)) {
                return ['html' => null, 'url' => $url, 'code' => 0];
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_FOLLOWLOCATION   => false,
                CURLOPT_TIMEOUT          => 12,
                CURLOPT_CONNECTTIMEOUT   => 6,
                CURLOPT_USERAGENT        => 'Mozilla/5.0 (compatible; NARH/' . NARH_VERSION . '; lecture locale)',
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_PROGRESSFUNCTION => static fn ($r, $recu) => $recu > self::TAILLE_MAX ? 1 : 0,
            ]);
            $corps = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $vers = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($code >= 300 && $code < 400 && $vers !== '') {
                $url = $vers;
                continue;
            }

            return ['html' => $corps === false ? null : (string) $corps, 'url' => $url, 'code' => $code];
        }

        return ['html' => null, 'url' => $url, 'code' => 0];
    }

    /**
     * Le lien répond-il ? Sans rapatrier le corps.
     *
     * Sert à `api/liens.php` : une source affichée qui mène à une 404 est pire
     * qu'une source absente — elle donne l'apparence de la vérifiabilité sans
     * la fournir. En HEAD d'abord, en GET tronqué ensuite : beaucoup de sites
     * de presse répondent 405 à un HEAD tout en servant la page.
     */
    public static function repond(string $url, int $timeout = 6): bool
    {
        if (!self::adresseSure($url)) {
            return false;
        }

        foreach ([true, false] as $tete) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => $tete,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NARH/' . NARH_VERSION . '; lecture locale)',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            // Une redirection est une réponse : le lien mène quelque part.
            if ($code >= 200 && $code < 400) {
                return true;
            }
            if ($code !== 405 && $code !== 403 && $code !== 0) {
                return false;
            }
        }

        return false;
    }

    /**
     * Ce paragraphe est-il de l'habillage plutôt que de l'article ?
     *
     * Mentions légales, encarts d'abonnement, bandeaux de cookies : ils passent
     * le seuil de longueur et se glissent en tête d'article, là où l'on lit
     * d'abord.
     *
     * Les motifs sont volontairement étroits et ancrés sur des formules figées :
     * un filtre large amputerait l'article, ce qui serait pire que de laisser
     * passer une ligne de pied de page. « Le maire a accédé à toutes les
     * demandes » doit survivre là où « Accédez à tous les contenus » disparaît.
     */
    public static function estHabillage(string $texte): bool
    {
        static $motifs = [
            '/©|\(c\)\s*copyright|\bcopyright\b/iu',
            '/tous droits r[ée]serv[ée]s/iu',
            '/site [ée]dit[ée] par|[ée]dit[ée] par\s+\w+\s*$/iu',
            '/mentions l[ée]gales|politique de confidentialit[ée]|conditions g[ée]n[ée]rales|\bcgu\b|\bcgv\b/iu',
            '/gestion des cookies|param[ée]trer les cookies|accepter les cookies|d[ée]p[ôo]t de cookies/iu',
            '/abonnez-vous|inscrivez-vous [àa] (notre|la) newsletter|recevez (chaque|toute)/iu',
            '/suivez-nous sur|retrouvez-nous sur|partagez cet article/iu',
            '/reproduction interdite|toute reproduction/iu',
            '/acc[ée]dez [àa] (tous|l\'ensemble)|en illimit[ée]\b/iu',
            '/t[ée]l[ée]chargez (gratuitement|l\'app|notre app)|disponible sur l\'app ?store|google play/iu',
            '/retrouvez tous nos contenus|[ée]dition du soir en num[ée]rique|journal en num[ée]rique/iu',
            '/offre d\'essai|sans engagement, r[ée]siliable|profitez de l\'offre/iu',

            /* Les renvois vers d'autres articles : « >> Lire aussi : … ». Ils
               sont longs, ressemblent à du texte, et n'apprennent rien — c'est
               le titre d'un autre papier, que la veille contient déjà. */
            '/^\s*(>>|»|→)?\s*([àa] )?(lire|voir|relire) (aussi|[ée]galement)\b/iu',
            '/^sur le m[êe]me (sujet|th[èe]me)|^dans la m[êe]me rubrique|^[àa] d[ée]couvrir/iu',
            '/^(notre|nos) (dossier|article|reportage)s? ?:/iu',

            /* Les encarts de collecte d'adresse, qui se glissent au milieu du
               texte et représentaient 3 % du corpus à la première ingestion —
               « France Télévisions utilise votre adresse e-mail pour vous
               adresser la newsletter… ».

               Les tournures sont visées précisément plutôt que les mots seuls :
               « newsletter » ou « consentement » apparaissent légitimement dans
               un article sur les médias ou sur le RGPD, et les bannir amputerait
               le corpus de ce qu'il a de plus intéressant sur ces sujets. */
            '/utilise votre adresse (e-?mail|courriel)|pour vous adresser (la|nos|notre)/iu',
            '/en vous inscrivant [àa] (nos|notre|la|ce)|susceptible d\'[êe]tre personnalis/iu',
            '/vous pouvez vous d[ée]sinscrire|d[ée]sinscri(re|ption) [àa] tout moment|lien de d[ée]sinscription/iu',
            '/g[ée]rer vos consentements|retirer votre consentement|politique de protection des donn[ée]es/iu',
        ];

        foreach ($motifs as $motif) {
            if (preg_match($motif, $texte) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les paragraphes et les images d'un document.
     *
     * Images choisies par signal structurel plutôt que par taille : mesuré, une
     * page de 20 Minutes porte 122 images dont une seule illustre l'article, et
     * une vignette de recirculation fait 252×162 — elle passe tous les seuils.
     * On retient donc `og:image`, que le site déclare lui-même, et les figures
     * **légendées** : une illustration porte une figcaption, une vignette
     * n'en a jamais.
     *
     * @return array{paragraphes: list<string>, images: list<array{src: string, alt: string}>}
     */
    public static function extraire(string $html, string $url, string $titre = ''): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $paragraphes = [];
        foreach ($xpath->query('//p') as $p) {
            $texte = trim(preg_replace('/\s+/u', ' ', $p->textContent) ?? '');

            if (mb_strlen($texte) < 80 || self::estHabillage($texte) || in_array($texte, $paragraphes, true)) {
                continue;
            }
            $paragraphes[] = $texte;

            if (count($paragraphes) >= 40) {
                break;
            }
        }

        $images = [];
        $vues = [];

        foreach (['og:image', 'twitter:image'] as $propriete) {
            $n = $xpath->query('//meta[@property="' . $propriete . '"]/@content | //meta[@name="' . $propriete . '"]/@content')->item(0);
            $src = $n !== null ? trim($n->nodeValue) : '';
            $absolue = $src !== '' ? self::absolu($src, $url) : null;

            if ($absolue !== null && !isset($vues[$absolue])) {
                $vues[$absolue] = true;
                $images[] = ['src' => $absolue, 'alt' => $titre];
            }
        }

        foreach ($xpath->query('//figure[figcaption]//img') as $img) {
            /** @var DOMElement $img */
            $src = trim($img->getAttribute('src'));

            // Le chargement différé range la vraie adresse ailleurs : sans ce
            // repli on ne récolterait que des images d'attente transparentes.
            foreach (['data-src', 'data-lazy-src', 'data-original', 'srcset', 'data-srcset'] as $repli) {
                if ($src === '' || str_starts_with($src, 'data:')) {
                    $autre = trim($img->getAttribute($repli));
                    if ($autre !== '') {
                        $src = explode(' ', trim(explode(',', $autre)[0]))[0];
                    }
                }
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $l = (int) $img->getAttribute('width');
            $h = (int) $img->getAttribute('height');
            if (($l > 0 && $l < 200) || ($h > 0 && $h < 150)) {
                continue;
            }

            $absolue = self::absolu($src, $url);
            if ($absolue === null || isset($vues[$absolue])) {
                continue;
            }
            $vues[$absolue] = true;
            $images[] = ['src' => $absolue, 'alt' => trim($img->getAttribute('alt'))];

            if (count($images) >= 12) {
                break;
            }
        }

        return ['paragraphes' => $paragraphes, 'images' => $images];
    }

    /**
     * Une adresse ramenée à l'absolu, ou null si elle ne mène nulle part.
     *
     * Les pages mêlent les trois formes — absolue, protocole implicite (`//…`)
     * et relative — et une image relative laissée telle quelle irait chercher
     * sur narh-agent.test, où elle n'existe pas.
     */
    private static function absolu(string $src, string $base): ?string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $b = parse_url($base);
        if (($b['host'] ?? '') === '') {
            return null;
        }
        $racine = ($b['scheme'] ?? 'https') . '://' . $b['host'];

        if (str_starts_with($src, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $src;
        }
        if (str_starts_with($src, '/')) {
            return $racine . $src;
        }

        $dossier = rtrim(dirname($b['path'] ?? '/'), '/');

        return $racine . $dossier . '/' . $src;
    }
}
