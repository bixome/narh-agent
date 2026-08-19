<?php
declare(strict_types=1);

/**
 * Les réglages du poste.
 *
 * Ce qui doit se lire sans base ni PHP applicatif vit ici ; tout le reste vivra
 * en base. `config/reglages.local.php`, jamais versionné, écrase ces valeurs.
 */

return [
    /* ---- Qui regarde ------------------------------------------------------
       Le nom sert d'identité à l'écran et de graine à l'avatar : le même nom
       redonne toujours le même motif, sans rien stocker ni télécharger. */

    'utilisateur' => 'Romain',

    /* ---- Stockage --------------------------------------------------------
       Deux fichiers, et c'est la règle 3 : la collecte est écrite en continu
       par le démon, la mémoire l'est par l'écran et par l'agent. Un seul
       fichier pour les deux, ce serait des verrous qu'on ne saurait pas
       reproduire. Les croisements passent par ATTACH, en lecture seule. */

    // La mémoire de NARH : journal, fils, messages, corpus.
    'base'        => NARH_VAR . '/narh.sqlite',
    // La collecte, écrite par `cli.php --veille`. Séparée de la mémoire parce
    // qu'un écrivain qui tourne toutes les 60 s ne se mélange pas avec l'écran
    // et le corpus : ce sont des verrous qu'on ne saurait pas reproduire.
    // (La raison d'origine — laisser Ekein-Scrapper tourner de son côté — est
    // caduque depuis son absorption ; la séparation, elle, tient toujours.)
    'base_veille' => NARH_VAR . '/actu.sqlite',
    'retention'   => 4,        // jours d'articles conservés

    /* ---- Rythme ---------------------------------------------------------- */

    // Période de sondage du navigateur, en secondes : ce qui est arrivé depuis
    // le dernier sondage s'insère à sa place, sans recharger la page.
    'sondage'         => 12,
    // Sondage quand l'onglet est en arrière-plan.
    'sondage_inactif' => 60,
    // Cadence par défaut d'une source, en secondes (surchargée source par source).
    'cadence'         => 90,
    // Une source qui échoue voit sa cadence doubler à chaque échec, jusqu'ici.
    'cadence_max'     => 1800,
    // Après ce nombre d'échecs d'affilée, la source est signalée « morte ».
    'echecs_morte'    => 6,

    /* ---- Collecte -------------------------------------------------------- */

    // L'écran déclenche lui-même un cycle si le dernier est plus vieux que la
    // cadence. Mettre à false quand `php cli.php --veille` tourne en fond :
    // l'écran devient un simple lecteur de la base, et répond en quelques ms.
    'collecte_web' => true,
    'cycle_max'    => 15,      // secondes — budget d'un cycle déclenché par le web
    'parallele'    => 16,      // requêtes HTTP simultanées
    'timeout'      => 8,
    'connexion'    => 4,
    'taille_max'   => 4194304, // 4 Mio par flux
    'agent'        => 'Mozilla/5.0 (compatible; NARH/' . NARH_VERSION . '; veille actualité)',

    /* ---- Alerte ----------------------------------------------------------
       Le lexique seul plafonne à 9 (Alerte::PLAFOND_LEXIQUE) : « urgent » ne
       s'atteint donc jamais sans reprise, et il en faut trois ou quatre. C'est
       le réglage qu'on retouche vraiment à l'usage — descendre `seuil_alerte`
       remplit le panneau, le monter le vide. */

    'seuil_veille' => 3,       // score → niveau 1
    'seuil_alerte' => 7,       // score → niveau 2
    'seuil_urgent' => 12,      // score → niveau 3
    // Fenêtre de regroupement : deux dépêches proches publiées dans cet
    // intervalle décrivent le même événement.
    'fenetre'      => 10800,   // 3 h
    'similarite'   => 0.42,    // seuil de Jaccard sur les mots significatifs
    'reprise_max'  => 5,       // points maximum gagnés par la reprise multi-source

    /* ---- Écran ----------------------------------------------------------- */

    'flux_max'    => 300,      // lignes rendues à l'ouverture du fil plat
    'arbre_max'   => 120,      // événements rendus à l'ouverture de l'arbre
    'alertes_max' => 12,
    'journal_max' => 60,       // entrées d'activité rendues dans la colonne de droite

    /* ---- Relance ---------------------------------------------------------
       Un événement qui a fait alerte puis s'est tu : soit il est retombé, soit
       personne ne l'a suivi — dans les deux cas c'est au desk d'en juger, et le
       fil ne le dira pas tout seul, une absence ne s'affiche pas.

       Le délai se mesure sur l'heure du relevé, jamais sur la date annoncée par
       le flux : `groupe.dernier` est un MAX(date_tri), il peut reculer. */

    'relance_minutes' => 45,
    'relance_niveau'  => 2,    // Alerte::ALERTE — en dessous, le silence est normal
    'debit_fenetre'   => 180,  // minutes
    'debit_pas'       => 10,   // minutes par tranche

    /* ---- Le moteur local -------------------------------------------------
       Rien ne l'interroge avant P2. Il est déclaré ici pour que le jour où on
       change de modèle, ce soit un réglage et non une retouche de code.

       `ia_marge` sert dès maintenant : la base s'en sert pour repérer les
       scores qui frôlent un seuil, indépendamment de tout modèle. */

    'ollama' => [
        'url'         => 'http://127.0.0.1:11434',
        'modele'      => 'qwen3:8b',
        'temperature' => 0.7,
        /* La fenêtre, en jetons — c'est un réglage de **machine**, pas de
           modèle : elle décide si le poids du modèle plus le cache tiennent
           dans la VRAM. Mesuré sur une RTX 3060 Ti (8 Gio) : qwen3:8b en Q4
           occupe ~5,2 Gio, et 8192 jetons de cache en ajoutent ~1. Au-delà,
           Ollama déborde en RAM et le débit est divisé par dix — sans rien
           dire, la réponse arrive juste dix fois plus tard. */
        'contexte'    => 8192,
    ],
    'ia_marge' => 2,

    /* Le second avis (`php cli.php --enrichir-ia`). Éteint par défaut : il
       n'ajoute rien qu'on attende, et une commande qui appelle un service
       absent doit le dire plutôt que d'échouer. À activer dans
       `config/reglages.local.php` quand on veut vraiment l'avis.

       `ia_lot` borne le passage : juger 7 600 événements d'un coup demanderait
       des heures à un modèle local pour un avis qui, par construction, ne
       décide de rien. */
    /* ---- La recherche sur le web ouvert -----------------------------------
       NARH ne voit que ce que ses flux lui apportent : un sujet qu'aucune
       source ne couvre n'existe pas pour lui, et il ne peut pas s'en rendre
       compte. `chercher_web` est sa seule fenêtre au-delà.

       Le point d'accès est **le seul endroit** d'où sort une adresse non
       vérifiée par `Lecture::adresseSure()` — c'est un service qu'on héberge,
       donc une plage privée. Il vient d'ici et jamais d'une réponse : le modèle
       ne fournit que le texte de la requête (voir `Lecture::service()`).

       Vide, l'outil n'est pas présenté au modèle du tout. Un catalogue qui
       annonce une capacité absente est pire qu'un catalogue plus court : le
       modèle répond qu'il sait chercher, puis ne trouve rien.

       SearXNG plutôt qu'une API commerciale : le projet ne charge aucun tiers
       dans l'écran et récupère tout côté serveur. Confier les requêtes de la
       rédaction à un moteur qui les identifie par une clé contredirait cette
       posture au moment même où l'on ouvre la porte.

           'point' => 'http://127.0.0.1:8888/search',

       Le service doit autoriser le format JSON (`search.formats` dans son
       settings.yml : SearXNG ne sert que du HTML par défaut). */
    'recherche' => [
        'point'   => '',
        'langue'  => 'fr',
        'timeout' => 8,
    ],

    /* ---- La réconciliation par vecteurs ----------------------------------
       Ce que le Jaccard ne sait pas voir : deux rédactions qui couvrent le même
       événement sans partager assez de mots. Mesuré sur cette base, avant
       correction : cinq groupes distincts pour un seul incendie en Lozère,
       trois pour une même rencontre Trump–Kim. Comme la reprise se compte en
       maisons et fait le score d'alerte, un événement majeur s'y présentait
       comme une poignée de faits mineurs.

       Éteint par défaut : il faut avoir tiré le modèle (`ollama pull bge-m3`)
       et une commande qui appelle un service absent doit le dire, pas échouer.

       `similarite` n'est pas un réglage délicat, contrairement à celui du
       Jaccard. Mesuré sur 120 paires de la base : les mêmes sujets tombent
       entre 0,76 et 0,97, les sujets différents entre 0,16 et 0,60. On coupe
       dans le fossé. Monter au-delà de 0,80 commence à perdre de vraies
       reprises ; descendre sous 0,65 rapproche des sujets voisins mais
       distincts — deux matchs de la même équipe, par exemple. */
    'vecteurs' => [
        'activee'    => false,
        'modele'     => 'bge-m3',
        'similarite' => 0.70,
        // Titres par appel. Au-delà, la connexion reste ouverte longtemps et
        // une coupure perdrait tout le passage plutôt qu'un lot.
        'lot'        => 64,
        // Le modèle ne pèse que 0,62 Gio, mais il partage la carte avec la
        // voix du direct (5,76 sur 8) : on le garde le temps du passage, pas
        // au-delà.
        'residence'  => 120,
    ],

    'ia_activee' => false,
    'ia_lot'     => 10,
    'ia_timeout' => 20,

    // Un pic d'arrivées : la dernière tranche dépasse la moyenne des autres
    // d'autant, et pèse au moins ce plancher — sans lui, une nuit calme fait un
    // « pic » à trois dépêches.
    'pic_facteur' => 2.5,
    'pic_min'     => 8,
];
