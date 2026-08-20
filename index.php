<?php
declare(strict_types=1);

/**
 * NARH — la surface.
 *
 * Une seule page, une seule lecture : la conversation. La veille, la mémoire,
 * le journal et l'inspecteur y entrent en tuiles, convoquées par une commande
 * ou posées par l'agent lui-même (voir `src/Tuile.php`).
 *
 * XOSHUI en mode console : `xo-app xo-console` sur le conteneur, et les mêmes
 * classes que partout ailleurs passent en rendu plein caractère. Aucun balisage
 * particulier, aucun composant maison — voir XOSHUI/docs/api.md, § Mode console.
 *
 * Toute la coquille vient de src/Ecran.php.
 */

require __DIR__ . '/bootstrap.php';

$c = Ecran::contexte();

$fil = Agent::filId();

/* La page ne fait que lire : le fil est connu, la session n'a plus rien à
   dire. Tout ce qui suit — les messages, la veille, le rendu — se fait sans
   tenir le verrou, sans quoi ouvrir un second onglet mettrait le premier en
   attente. */
Agent::filRendreLaMain();

$tours = $fil > 0 ? Memoire::messages($fil) : [];

/* Le pont, sens veille → agent : `?depeche=` désigne la dépêche sur laquelle on
   veut interroger l'agent. L'écran ne l'écrit pas encore dans le fil — c'est le
   premier message qui la versera au dossier. Ancrer à l'ouverture laisserait une
   trace pour un simple coup d'œil. */
$ancre = null;
if (($id = (int) ($_GET['depeche'] ?? 0)) > 0) {
    $ancre = (new Base((string) narh_reglage('base_veille')))->article($id);
}

echo Ecran::rendre($c, $tours, $ancre);
