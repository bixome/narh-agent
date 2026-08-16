<?php
declare(strict_types=1);

/**
 * Les icônes — du chrome, jamais du contenu.
 *
 * NARH parle deux langues visuelles et il ne faut pas les mélanger :
 *
 * - les **glyphes de caractère** (`·○◆●`, `▸▾`, `›`) portent l'information
 *   dans les lignes : nature, intensité, pliage. Ils occupent une cellule de la
 *   grille et s'alignent avec le texte. On n'y touche pas.
 * - les **icônes** habillent ce qui n'est pas une ligne : boutons, titres de
 *   tuile, entrées de menu, barre d'état.
 *
 * Remplacer les premiers par les secondes casserait l'alignement colonne par
 * colonne et donnerait deux vocabulaires concurrents pour la même chose — c'est
 * exactement ce que la doctrine interdit.
 *
 * Trois propriétés font qu'une icône picon tient dans la charte sans y toucher :
 * un seul tracé sans remplissage déclaré, donc **la couleur est celle du texte**
 * (aucun hex en dur, les tokens `xo-*` la colorent) ; une grille de 8, donc
 * lisible à la taille d'un caractère ; et le tracé est inliné, donc **aucune
 * ressource réseau**.
 *
 * Elles vivent ici et pas dans XOSHUI : c'est un choix de NARH, et le framework
 * n'a pas à porter le vocabulaire d'une application.
 */
final class Icone
{
    /** @var array<string, string>|null */
    private static ?array $traces = null;

    /**
     * Une icône, à la taille d'un caractère.
     *
     * Logée dans la cellule (`1ch` de large) plutôt que débordante : la grille
     * prime, et le pack est dessiné pour rester net à huit pixels. Le
     * `viewBox` carré et le cadrage par défaut la centrent dans la hauteur de
     * ligne sans jamais élargir la colonne.
     *
     * @param string $ton un utilitaire XOSHUI sans son préfixe : accent, warning…
     */
    public static function rendre(string $nom, string $ton = ''): string
    {
        $trace = self::traces()[$nom] ?? null;
        if ($trace === null) {
            // Une icône absente ne casse pas l'écran : elle laisse sa cellule
            // vide. Le vocabulaire se corrige dans tools/icones.php, pas ici.
            return '<span class="xo-icon" aria-hidden="true"></span>';
        }

        $classe = 'xo-icon' . ($ton !== '' ? ' xo-' . $ton : '');

        return '<span class="' . $classe . '" aria-hidden="true">'
            . '<svg viewBox="0 0 8 8" fill="currentColor" style="width: 1ch; height: 1em; vertical-align: -0.15em">'
            . '<path d="' . e($trace) . '"></path>'
            . '</svg></span>';
    }

    /** @return array<string, string> */
    private static function traces(): array
    {
        if (self::$traces === null) {
            $fichier = NARH_RACINE . '/config/icones.php';
            $traces = is_file($fichier) ? require $fichier : [];
            self::$traces = is_array($traces) ? $traces : [];
        }

        return self::$traces;
    }
}
