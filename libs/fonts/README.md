# libs/fonts/

XOSHUI est écrit en **JetBrains Mono**, auto-hébergée. Aucune requête réseau :
la règle « aucune ressource externe » vaut aussi pour les polices, et le linter
refuse tout lien vers un CDN.

## Fichiers utilisés

| Chemin | Graisse |
|---|---|
| `webfonts/JetBrainsMono-Regular.woff2` | 400 |
| `webfonts/JetBrainsMono-Bold.woff2` | 700 |

Ce sont les deux chemins déclarés dans `libs/css/xoshui.css`. Le framework
n'utilise que ces deux graisses et aucune italique.

Le dossier `webfonts/` reprend tel quel celui de l'archive officielle, qui
contient les seize autres variantes — non utilisées, mais conservées pour
pouvoir en ajouter une sans retourner chercher l'archive.

## Où les prendre

<https://github.com/JetBrains/JetBrainsMono> — archive `JetBrainsMono-x.xxx.zip`,
dossier `fonts/webfonts/`.

## Tant qu'ils sont absents

Rien ne casse : le navigateur passe silencieusement à la pile système
(`ui-monospace`, `Cascadia Mono`, `Consolas`, `DejaVu Sans Mono`,
`Courier New`). La seule trace est un 404 dans la console.

## Licence

SIL Open Font License 1.1 — l'embarquement est autorisé, à condition de
conserver le fichier de licence. Déposer `OFL.txt` dans ce dossier — il **manque** actuellement.

## Ligatures

Désactivées (`font-variant-ligatures: none` sur `body`). JetBrains Mono garde
sa chasse fixe avec les ligatures, donc la grille tiendrait — mais aucun
terminal ne fusionne `!=` ou `->`, et un diff se lit caractère à caractère.
Pour les réactiver : retirer la déclaration dans la section 2 de `xoshui.css`.
