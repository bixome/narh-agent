<?php
declare(strict_types=1);

/**
 * Les flux.
 *
 * Tous vérifiés le 14/08/2026 : code 200, XML valide, articles datés.
 * `php cli.php --verifier` refait le contrôle et signale ceux qui ont bougé.
 *
 * | Clé      | Rôle                                                             |
 * |----------|------------------------------------------------------------------|
 * | id       | Identifiant stable — sert de clé en base, ne jamais le renommer   |
 * | nom      | Ce qui s'affiche dans la colonne source (14 caractères au plus)   |
 * | url      | Le flux RSS, Atom ou RDF                                          |
 * | rubrique | une · france · monde · eco · tech · sciences · regional · veille · social |
 * | cadence  | Secondes entre deux relevés — 60 pour les fils vraiment rapides   |
 * | poids    | Confiance éditoriale : compte dans le score de reprise            |
 * | maison   | La rédaction derrière le flux — plusieurs flux la partagent       |
 * | rang     | continu · redaction · agregateur · social                         |
 * | actif    | false met la source de côté sans la supprimer                     |
 *
 * La cadence n'est pas un coût : une source non modifiée répond 304 sans corps
 * (voir Http, en-têtes If-None-Match / If-Modified-Since). Descendre sous 60 s
 * n'apporte rien — aucune rédaction ne republie son fil plus vite.
 *
 * **`maison` compte plus que `id`.** La reprise mesure combien de rédactions
 * indépendantes titrent le même fait. Le Monde publie cinq flux, BFM cinq,
 * francetvinfo six : comptés par flux, un événement que Le Monde porte seul
 * vaut « ×5 rédactions ». Le rapprochement se fait donc par maison, et le
 * poids retenu est le meilleur de ses flux.
 *
 * **`rang` dit ce que vaut une confirmation.** `continu` est un fil qui
 * republie vite la copie d'agence — il arrive en premier, mais deux fils
 * continus disent souvent la même dépêche AFP. `agregateur` ne confirme rien
 * du tout : Google Actualités recopie une source déjà comptée, et n'entre pas
 * dans le calcul de reprise.
 */

return [

    /* ---- Fils rapides : ce qui tombe en premier -------------------------- */

    ['id' => 'bfmtv',       'nom' => 'BFMTV 24/7',  'url' => 'https://www.bfmtv.com/rss/news-24-7/',                    'rubrique' => 'une',    'cadence' => 60,  'poids' => 1.0, 'maison' => 'bfm',        'rang' => 'continu'],
    ['id' => 'ftv-titres',  'nom' => 'franceinfo',  'url' => 'https://www.francetvinfo.fr/titres.rss',                  'rubrique' => 'une',    'cadence' => 60,  'poids' => 1.2, 'maison' => 'francetv',   'rang' => 'continu'],
    ['id' => 'gnews-une',   'nom' => 'Google Actu', 'url' => 'https://news.google.com/rss?hl=fr&gl=FR&ceid=FR:fr',      'rubrique' => 'une',    'cadence' => 60,  'poids' => 0.4, 'maison' => 'gnews',      'rang' => 'agregateur'],
    ['id' => 'francebleu',  'nom' => 'ici (Bleu)',  'url' => 'https://www.francebleu.fr/rss/a-la-une.xml',              'rubrique' => 'une',    'cadence' => 90,  'poids' => 0.9, 'maison' => 'francebleu', 'rang' => 'continu'],
    ['id' => 'rmc',         'nom' => 'RMC',         'url' => 'https://rmc.bfmtv.com/rss/actualites/',                   'rubrique' => 'une',    'cadence' => 90,  'poids' => 0.8, 'maison' => 'rmc',        'rang' => 'continu'],
    ['id' => 'figaro-flash','nom' => 'Figaro Flash','url' => 'https://www.lefigaro.fr/rss/figaro_flash-actu.xml',       'rubrique' => 'une',    'cadence' => 90,  'poids' => 1.0, 'maison' => 'figaro',     'rang' => 'continu'],
    ['id' => 'lemonde-une', 'nom' => 'Le Monde',    'url' => 'https://www.lemonde.fr/rss/une.xml',                      'rubrique' => 'une',    'cadence' => 90,  'poids' => 1.3, 'maison' => 'lemonde'],
    ['id' => 'leparisien',  'nom' => 'Le Parisien', 'url' => 'https://feeds.leparisien.fr/leparisien/rss',              'rubrique' => 'une',    'cadence' => 90,  'poids' => 1.0, 'maison' => 'leparisien'],
    ['id' => '20min-une',   'nom' => '20 Minutes',  'url' => 'https://www.20minutes.fr/feeds/rss-une.xml',              'rubrique' => 'une',    'cadence' => 90,  'poids' => 0.9, 'maison' => '20minutes',  'rang' => 'continu'],
    ['id' => 'liberation',  'nom' => 'Libération',  'url' => 'https://www.liberation.fr/arc/outboundfeeds/rss-all/?outputType=xml', 'rubrique' => 'une', 'cadence' => 120, 'poids' => 1.1, 'maison' => 'liberation'],
    ['id' => 'nouvelobs',   'nom' => 'L\'Obs',      'url' => 'https://www.nouvelobs.com/rss.xml',                       'rubrique' => 'une',    'cadence' => 120, 'poids' => 1.0, 'maison' => 'nouvelobs'],
    ['id' => 'lexpress',    'nom' => 'L\'Express',  'url' => 'https://www.lexpress.fr/rss/alaune.xml',                  'rubrique' => 'une',    'cadence' => 120, 'poids' => 1.0, 'maison' => 'lexpress'],
    ['id' => 'huffpost',    'nom' => 'HuffPost',    'url' => 'https://www.huffingtonpost.fr/feeds/index.xml',           'rubrique' => 'une',    'cadence' => 150, 'poids' => 0.8, 'maison' => 'huffpost'],
    ['id' => 'lacroix',     'nom' => 'La Croix',    'url' => 'https://www.la-croix.com/RSS/UNIVERS',                    'rubrique' => 'une',    'cadence' => 180, 'poids' => 1.0, 'maison' => 'lacroix'],
    ['id' => 'europe1',     'nom' => 'Europe 1',    'url' => 'https://www.europe1.fr/rss.xml',                          'rubrique' => 'une',    'cadence' => 120, 'poids' => 0.9, 'maison' => 'europe1',    'rang' => 'continu'],
    ['id' => 'rf-info',     'nom' => 'Radio France','url' => 'https://www.radiofrance.fr/franceinfo/rss',               'rubrique' => 'une',    'cadence' => 120, 'poids' => 1.1, 'maison' => 'radiofrance','rang' => 'continu'],

    /* ---- France ---------------------------------------------------------- */

    ['id' => 'ftv-france',  'nom' => 'fi/France',   'url' => 'https://www.francetvinfo.fr/france.rss',                  'rubrique' => 'france', 'cadence' => 120, 'poids' => 1.1, 'maison' => 'francetv'],
    ['id' => 'ftv-divers',  'nom' => 'fi/Divers',   'url' => 'https://www.francetvinfo.fr/faits-divers.rss',            'rubrique' => 'france', 'cadence' => 120, 'poids' => 1.1, 'maison' => 'francetv'],
    ['id' => 'ftv-politiq', 'nom' => 'fi/Politique','url' => 'https://www.francetvinfo.fr/politique.rss',               'rubrique' => 'france', 'cadence' => 150, 'poids' => 1.1, 'maison' => 'francetv'],
    ['id' => 'bfm-police',  'nom' => 'BFM Justice', 'url' => 'https://www.bfmtv.com/rss/police-justice/',               'rubrique' => 'france', 'cadence' => 120, 'poids' => 1.0, 'maison' => 'bfm'],
    ['id' => 'bfm-politiq', 'nom' => 'BFM Politiq.','url' => 'https://www.bfmtv.com/rss/politique/',                    'rubrique' => 'france', 'cadence' => 150, 'poids' => 1.0, 'maison' => 'bfm'],
    ['id' => 'lemonde-pol', 'nom' => 'LM/Politique','url' => 'https://www.lemonde.fr/politique/rss_full.xml',           'rubrique' => 'france', 'cadence' => 180, 'poids' => 1.3, 'maison' => 'lemonde'],
    ['id' => 'rfi-france',  'nom' => 'RFI France',  'url' => 'https://www.rfi.fr/fr/france/rss',                        'rubrique' => 'france', 'cadence' => 180, 'poids' => 1.1, 'maison' => 'rfi'],
    ['id' => 'f24-france',  'nom' => 'F24 France',  'url' => 'https://www.france24.com/fr/france/rss',                  'rubrique' => 'france', 'cadence' => 180, 'poids' => 1.1, 'maison' => 'france24'],

    /* ---- Monde ----------------------------------------------------------- */

    ['id' => 'ftv-monde',   'nom' => 'fi/Monde',    'url' => 'https://www.francetvinfo.fr/monde.rss',                   'rubrique' => 'monde',  'cadence' => 120, 'poids' => 1.1, 'maison' => 'francetv'],
    ['id' => 'bfm-inter',   'nom' => 'BFM Monde',   'url' => 'https://www.bfmtv.com/rss/international/',                'rubrique' => 'monde',  'cadence' => 120, 'poids' => 1.0, 'maison' => 'bfm'],
    ['id' => 'rfi',         'nom' => 'RFI',         'url' => 'https://www.rfi.fr/fr/rss',                               'rubrique' => 'monde',  'cadence' => 120, 'poids' => 1.2, 'maison' => 'rfi'],
    ['id' => 'france24',    'nom' => 'France 24',   'url' => 'https://www.france24.com/fr/rss',                         'rubrique' => 'monde',  'cadence' => 120, 'poids' => 1.2, 'maison' => 'france24'],
    ['id' => 'lemonde-int', 'nom' => 'LM/Inter',    'url' => 'https://www.lemonde.fr/international/rss_full.xml',       'rubrique' => 'monde',  'cadence' => 150, 'poids' => 1.3, 'maison' => 'lemonde'],
    ['id' => 'figaro-int',  'nom' => 'Figaro Int.', 'url' => 'https://www.lefigaro.fr/rss/figaro_international.xml',    'rubrique' => 'monde',  'cadence' => 180, 'poids' => 1.0, 'maison' => 'figaro'],
    ['id' => 'euronews',    'nom' => 'Euronews',    'url' => 'https://fr.euronews.com/rss?level=theme&name=news',       'rubrique' => 'monde',  'cadence' => 150, 'poids' => 1.0, 'maison' => 'euronews'],
    ['id' => 'courrier',    'nom' => 'Courrier I.', 'url' => 'https://www.courrierinternational.com/feed/all/rss.xml',  'rubrique' => 'monde',  'cadence' => 300, 'poids' => 1.0, 'maison' => 'courrier'],
    ['id' => '20min-monde', 'nom' => '20min Monde', 'url' => 'https://www.20minutes.fr/feeds/rss-monde.xml',            'rubrique' => 'monde',  'cadence' => 180, 'poids' => 0.9, 'maison' => '20minutes'],
    ['id' => 'rts',         'nom' => 'RTS (CH)',    'url' => 'https://www.rts.ch/info/?format=rss/news',                'rubrique' => 'monde',  'cadence' => 180, 'poids' => 1.0, 'maison' => 'rts'],
    ['id' => 'rtbf',        'nom' => 'RTBF (BE)',   'url' => 'https://rss.rtbf.be/article/rss/highlight_rtbfinfo_info.xml', 'rubrique' => 'monde', 'cadence' => 180, 'poids' => 1.0, 'maison' => 'rtbf'],

    /* ---- Économie -------------------------------------------------------- */

    ['id' => 'lemonde-eco', 'nom' => 'LM/Éco',      'url' => 'https://www.lemonde.fr/economie/rss_full.xml',            'rubrique' => 'eco',    'cadence' => 180, 'poids' => 1.3, 'maison' => 'lemonde'],
    ['id' => 'bfm-eco',     'nom' => 'BFM Éco',     'url' => 'https://www.bfmtv.com/rss/economie/',                     'rubrique' => 'eco',    'cadence' => 150, 'poids' => 1.0, 'maison' => 'bfm'],
    ['id' => 'ftv-eco',     'nom' => 'fi/Éco',      'url' => 'https://www.francetvinfo.fr/economie.rss',                'rubrique' => 'eco',    'cadence' => 180, 'poids' => 1.1, 'maison' => 'francetv'],
    ['id' => 'latribune',   'nom' => 'La Tribune',  'url' => 'https://www.latribune.fr/feed.xml',                       'rubrique' => 'eco',    'cadence' => 240, 'poids' => 1.0, 'maison' => 'latribune'],
    ['id' => 'usine-nouv',  'nom' => 'Usine Nouv.', 'url' => 'https://www.usinenouvelle.com/rss',                       'rubrique' => 'eco',    'cadence' => 300, 'poids' => 0.9, 'maison' => 'usinenouvelle'],

    /* ---- Technologie ----------------------------------------------------- */

    ['id' => 'nextink',     'nom' => 'Next',        'url' => 'https://next.ink/feed/free/',                             'rubrique' => 'tech',   'cadence' => 300, 'poids' => 1.0, 'maison' => 'next'],
    ['id' => 'numerama',    'nom' => 'Numerama',    'url' => 'https://www.numerama.com/feed/',                          'rubrique' => 'tech',   'cadence' => 300, 'poids' => 0.9, 'maison' => 'numerama'],
    ['id' => 'zdnet',       'nom' => 'ZDNet',       'url' => 'https://www.zdnet.fr/feeds/rss/actualites/',              'rubrique' => 'tech',   'cadence' => 300, 'poids' => 0.9, 'maison' => 'zdnet'],
    ['id' => 'clubic',      'nom' => 'Clubic',      'url' => 'https://www.clubic.com/feed/news.rss',                    'rubrique' => 'tech',   'cadence' => 300, 'poids' => 0.7, 'maison' => 'clubic'],

    /* ---- Sciences, environnement ----------------------------------------- */

    ['id' => 'lm-planete',  'nom' => 'LM/Planète',  'url' => 'https://www.lemonde.fr/planete/rss_full.xml',             'rubrique' => 'sciences', 'cadence' => 240, 'poids' => 1.2, 'maison' => 'lemonde'],
    ['id' => 'sci-avenir',  'nom' => 'Sci. Avenir', 'url' => 'https://www.sciencesetavenir.fr/rss.xml',                 'rubrique' => 'sciences', 'cadence' => 300, 'poids' => 0.9, 'maison' => 'sciencesetavenir'],

    /* ---- Régions : là où l'incident local est vu avant Paris -------------- */

    ['id' => 'ouest-une',   'nom' => 'Ouest-France','url' => 'https://www.ouest-france.fr/rss/une',                     'rubrique' => 'regional', 'cadence' => 120, 'poids' => 1.1, 'maison' => 'ouestfrance'],
    ['id' => 'sudouest',    'nom' => 'Sud Ouest',   'url' => 'https://www.sudouest.fr/rss.xml',                         'rubrique' => 'regional', 'cadence' => 150, 'poids' => 1.0, 'maison' => 'sudouest'],
    ['id' => 'ladepeche',   'nom' => 'La Dépêche',  'url' => 'https://www.ladepeche.fr/rss.xml',                        'rubrique' => 'regional', 'cadence' => 150, 'poids' => 1.0, 'maison' => 'ladepeche'],
    ['id' => 'lindependant','nom' => 'L\'Indép.',   'url' => 'https://www.lindependant.fr/rss.xml',                     'rubrique' => 'regional', 'cadence' => 180, 'poids' => 0.9, 'maison' => 'lindependant'],
    ['id' => 'voixdunord',  'nom' => 'Voix du Nord','url' => 'https://www.lavoixdunord.fr/rss.xml',                     'rubrique' => 'regional', 'cadence' => 180, 'poids' => 1.0, 'maison' => 'voixdunord'],
    ['id' => 'letelegramme','nom' => 'Le Télégramme','url' => 'https://www.letelegramme.fr/rss.xml',                    'rubrique' => 'regional', 'cadence' => 180, 'poids' => 1.0, 'maison' => 'letelegramme'],
    ['id' => 'dna',         'nom' => 'DNA',         'url' => 'https://www.dna.fr/rss',                                  'rubrique' => 'regional', 'cadence' => 240, 'poids' => 0.9, 'maison' => 'dna'],

    /* ---- Veille ciblée ---------------------------------------------------
       Des requêtes Google Actualités plutôt que des rubriques : elles ratissent
       tout le web francophone sur les mots qui accompagnent une alerte. Poids
       faible et rang `agregateur` : ils font remonter un sujet, ils ne le
       confirment jamais — la reprise les ignore. */

    ['id' => 'gnews-fr',    'nom' => 'GN France',   'url' => 'https://news.google.com/rss/headlines/section/topic/NATION?hl=fr&gl=FR&ceid=FR:fr', 'rubrique' => 'veille', 'cadence' => 120, 'poids' => 0.4, 'maison' => 'gnews', 'rang' => 'agregateur'],
    ['id' => 'gnews-monde', 'nom' => 'GN Monde',    'url' => 'https://news.google.com/rss/headlines/section/topic/WORLD?hl=fr&gl=FR&ceid=FR:fr',  'rubrique' => 'veille', 'cadence' => 120, 'poids' => 0.4, 'maison' => 'gnews', 'rang' => 'agregateur'],
    ['id' => 'gnews-alerte','nom' => 'GN Alerte',   'url' => 'https://news.google.com/rss/search?q=alerte+OR+urgent+OR+%22en+direct%22+when:1d&hl=fr&gl=FR&ceid=FR:fr', 'rubrique' => 'veille', 'cadence' => 90, 'poids' => 0.4, 'maison' => 'gnews', 'rang' => 'agregateur'],

    /* ---- Web social ------------------------------------------------------
       Vérifiés le 15/08/2026 : code 200, Atom/RSS valide, entrées datées.

       Rang `social`, et c'est là tout le sujet : ici, la reprise ne veut rien
       dire. Cinq rédactions qui titrent le même fait le confirment ; cinq cents
       commentaires sous un fil ne font que le commenter. Comptée comme une
       reprise, une rumeur virale scorerait comme une dépêche vérifiée — d'où
       l'exclusion dans recalculerGroupe(), au même titre qu'`agregateur`.

       Poids bas pour la même raison : ces sources disent ce qui se discute,
       jamais ce qui est établi.

       **Reddit limite le débit sans OAuth.** Mesuré le 15/08 : environ une
       requête sur deux répond 429, même espacées de quelques secondes. D'où une
       cadence très lâche et un seul subreddit — en déclarer plusieurs les ferait
       échouer ensemble dans le même lot curl_multi. Les échecs sont sans gravité
       (le recul exponentiel les réessaie), mais la source paraîtra fragile dans
       le panneau : c'est attendu, pas une panne. */

    ['id' => 'reddit-fr',   'nom' => 'r/france',    'url' => 'https://www.reddit.com/r/france/.rss',                    'rubrique' => 'social', 'cadence' => 900, 'poids' => 0.3, 'maison' => 'reddit',  'rang' => 'social'],
    ['id' => 'hackernews',  'nom' => 'HackerNews',  'url' => 'https://news.ycombinator.com/rss',                        'rubrique' => 'social', 'cadence' => 600, 'poids' => 0.4, 'maison' => 'hn',      'rang' => 'social'],
    ['id' => 'linuxfr',     'nom' => 'LinuxFr',     'url' => 'https://linuxfr.org/news.atom',                           'rubrique' => 'social', 'cadence' => 900, 'poids' => 0.4, 'maison' => 'linuxfr', 'rang' => 'social'],

];
