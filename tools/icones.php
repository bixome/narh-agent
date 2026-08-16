<?php
declare(strict_types=1);

/**
 * Extrait de picon les seules icônes que NARH emploie.
 *
 *   php tools/icones.php [chemin/vers/picon/solid]
 *
 * Écrit `config/icones.php` : un tableau `nom => tracé`. Rien d'autre n'est
 * copié — ni fichiers servis, ni police, ni sprite. Le tracé est inliné par
 * `src/Icone.php` au moment du rendu, ce qui donne trois choses d'un coup :
 * aucune requête réseau, la couleur héritée du texte (donc aucun hex en dur),
 * et le balisage produit par PHP comme tout le reste (règle 2).
 *
 * Pourquoi une liste blanche plutôt que les 910 : on n'embarque pas neuf cents
 * tracés pour en afficher quinze, et surtout une icône qui n'est pas dans cette
 * liste n'a pas été choisie — la liste *est* le vocabulaire visuel du projet.
 * L'étendre est une décision, pas un effet de bord.
 *
 * picon est publié sous licence libre (OFL) — voir docs/licences.md.
 */

const PICON_DEFAUT = 'C:/Users/Romain/Downloads/picon-21.12.05/picon-21.12.05/solid';

/**
 * Le vocabulaire de NARH : nom d'usage => nom dans le pack.
 *
 * Le nom d'usage dit le **rôle**, pas le dessin : `alerte` plutôt que `bell`.
 * Le jour où la cloche ne convient plus, on change une ligne ici et pas les
 * douze endroits qui l'affichent.
 */
const ICONES = [
    // Les trois tuiles d'en-tête
    'utilisateur' => 'profile',
    'alerte'      => 'bell',
    'regime'      => 'broadcast',

    // Le régime et la collecte
    'direct'      => 'play',
    'antenne-fin' => 'stop',
    'relever'     => 'refresh',
    'veille'      => 'newspaper',
    'moteur'      => 'cpu',

    // Les commandes
    'inspecter'   => 'eye',
    'journal'     => 'clock',
    'memoire'     => 'archive',
    'fils'        => 'chat',
    'fil-neuf'    => 'add',
    'oublier'     => 'trash',
    'suivre'      => 'bookmark',
    'traite'      => 'checked',
    'ecarter'     => 'crossed',
    'ouvrir'      => 'external',
    'interroger'  => 'chatbot',
    'reglages'    => 'cog',
    'chercher'    => 'magnifier',
    'invite'      => 'code',
];

$source = $argv[1] ?? PICON_DEFAUT;
if (!is_dir($source)) {
    exit("Dossier picon introuvable : $source\n");
}

/* Les noms de fichiers portent leurs étiquettes : « bell.#bell.%e2%90%87.#fb0.svg ».
   Le nom utile est le premier segment, avant le premier point. */
$parPack = [];
foreach (scandir($source) ?: [] as $fichier) {
    if (!str_ends_with($fichier, '.svg')) {
        continue;
    }
    $nom = explode('.', $fichier)[0];
    if ($nom !== '' && !isset($parPack[$nom])) {
        $parPack[$nom] = $source . '/' . $fichier;
    }
}

$sortie = [];
$manquants = [];

foreach (ICONES as $usage => $nomPack) {
    if (!isset($parPack[$nomPack])) {
        $manquants[] = "$usage ($nomPack)";
        continue;
    }

    $svg = (string) file_get_contents($parPack[$nomPack]);

    /* On ne garde que les tracés, jamais le `<svg>` du pack : sa taille, sa
       couleur et son `viewBox` sont décidés au rendu, pas ici. Plusieurs
       `<path>` sont concaténés — certaines icônes en ont deux. */
    if (preg_match_all('/<path[^>]*\bd="([^"]+)"/', $svg, $m) === 0) {
        $manquants[] = "$usage (aucun tracé)";
        continue;
    }

    $sortie[$usage] = implode(' ', $m[1]);
}

$php = "<?php\ndeclare(strict_types=1);\n\n"
    . "/**\n"
    . " * Les tracés des icônes, extraits de picon par tools/icones.php.\n"
    . " *\n"
    . " * NE PAS ÉDITER À LA MAIN : régénérer avec `php tools/icones.php`.\n"
    . " * Toutes sont dessinées sur une grille de 8 — d'où le viewBox de Icone.\n"
    . " */\n\n"
    . 'return ' . var_export($sortie, true) . ";\n";

file_put_contents(__DIR__ . '/../config/icones.php', $php);

printf("%d icônes écrites dans config/icones.php\n", count($sortie));
if ($manquants !== []) {
    printf("manquantes : %s\n", implode(', ', $manquants));
}
