<?php
declare(strict_types=1);

/**
 * Les conduites — ce que NARH fait tout seul.
 *
 * C'est la règle 6 rendue exécutable : une conduite n'est **pas** une mécanique
 * nouvelle, c'est une commande qui existe déjà, branchée sur un seuil, un mot
 * ou une maison. Rien ici ne sait agir ; tout délègue à `Ecran::COMMANDES`,
 * exactement comme la palette et le clic droit.
 *
 * | Clé      | Rôle                                                            |
 * |----------|-----------------------------------------------------------------|
 * | nom      | Identifiant stable — sert de clé de mémoire, ne pas le renommer  |
 * | dit      | Ce qui s'affiche quand elle tire, à la première personne du desk |
 * | quand    | Le déclencheur : toutes les clés présentes doivent être vraies   |
 * | faire    | La commande à jouer — obligatoirement `auto` dans COMMANDES      |
 * | actif    | false la met de côté sans la supprimer                           |
 *
 * Les clés reconnues dans `quand` :
 *
 * | Clé         | Vrai quand…                                                  |
 * |-------------|--------------------------------------------------------------|
 * | niveau      | le niveau de l'événement atteint au moins celui-ci (Alerte::*)|
 * | maisons     | au moins N rédactions **distinctes** titrent dessus           |
 * | maisons_max | au plus N — ce que personne ne reprend                        |
 * | mots        | le titre normalisé contient l'un de ces mots                  |
 * | rubrique    | l'événement vient de cette rubrique (voir Ecran::RUBRIQUES)   |
 *
 * **`maisons` ne compte pas ce qui ne confirme pas.** Les agrégateurs et le web
 * social sortent du calcul de reprise (`recalculerGroupe`) : un billet social
 * que personne ne relaie vaut **zéro** maison, pas une. Écrire `maisons: 1`
 * pour dire « au moins lui-même » donne une conduite qui ne tire jamais — et un
 * silence pareil ne se distingue pas d'un réglage qui marche. `maisons_max`
 * existe pour ce cas.
 *
 * **Un seuil se lit avec la reprise, jamais sans.** `mots` seul sur un sujet
 * qu'une seule rédaction porte marquerait une tournure de titre — c'est
 * précisément ce que `Alerte::PLAFOND_LEXIQUE` refuse de faire au score. Une
 * conduite qui n'exige aucune reprise hérite du même défaut, en plus bruyant :
 * elle agit. D'où le `maisons` présent partout ci-dessous, même bas.
 *
 * **Une conduite ne tire qu'une fois par événement.** La mémoire vit en base
 * (`conduite_vu`), comme celle de l'antenne : sans elle, un événement qui reste
 * chaud deux heures rejouerait la même commande à chaque cycle — quarante fois
 * la même note dans la chronologie, et le journal deviendrait illisible au
 * moment précis où il compte.
 */

return [

    /* ---- Le desk ---------------------------------------------------------
       Marquer, c'est ce qu'un chef d'édition fait sans qu'on le lui demande :
       un fait que cinq rédactions portent est suivi, qu'on l'ait vu ou non.
       C'est aussi le geste le moins risqué à confier à une machine — il est
       réversible d'un clic, et `purger()` épargne ce qui est suivi. */

    [
        'nom'   => 'suivre-les-alertes',
        'dit'   => 'Alerte confirmée — mise en suivi',
        'quand' => ['niveau' => Alerte::ALERTE, 'maisons' => 3],
        'faire' => 'suivi',
        'actif' => true,
    ],

    /* Le seuil de reprise seul, sans lexique : cinq rédactions indépendantes
       sur le même quart d'heure est un fait, quel que soit le vocabulaire. Le
       lexique rate ce qui n'a pas de mot noir — une démission, un verdict, un
       résultat — et c'est exactement ce que la reprise sait voir. */
    [
        'nom'   => 'suivre-la-reprise-large',
        'dit'   => 'Reprise large — mise en suivi',
        'quand' => ['maisons' => 5],
        'faire' => 'suivi',
        'actif' => true,
    ],

    /* ---- Écarter ---------------------------------------------------------
       Le rang `social` est ce qui se discute, pas ce qui est établi. Un billet
       qu'aucune rédaction ne reprend est une opinion : il a sa place dans la
       collecte, aucune au desk. Les écarter un à un tous les matins est un
       travail de copiste — c'est le premier endroit où une conduite rend du
       temps, et le moins coûteux si elle se trompe.

       Livrée inactive : écarter est le seul geste de cette liste qui **retire**
       quelque chose de la vue, et on ne l'allume qu'après avoir regardé ce
       qu'il aurait pris. `php cli.php --conduites` le montre sans rien faire. */

    [
        'nom'   => 'ecarter-le-social-isole',
        'dit'   => 'Billet social sans reprise — écarté du desk',
        'quand' => ['rubrique' => 'social', 'maisons_max' => 0],
        'faire' => 'ecarte',
        'actif' => false,
    ],

    /* ---- L'agent ---------------------------------------------------------
       La boucle, dans son sens plein : ce qui arrive déclenche ce qui pense.
       Réservée au niveau le plus haut — `interroger` coûte des secondes de
       modèle et un fil, là où marquer coûte une écriture. Une conduite qui
       interroge sur chaque alerte produirait quarante réponses par nuit que
       personne ne lira. */

    [
        'nom'   => 'briefer-sur-urgent',
        'dit'   => "Urgent confirmé — briefing demandé à l'agent",
        'quand' => ['niveau' => Alerte::URGENT, 'maisons' => 4],
        'faire' => 'interroger',
        'actif' => true,
    ],
];
