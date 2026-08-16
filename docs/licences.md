# Licences

NARH n'embarque aucune dépendance installée : ce qui vient d'ailleurs est copié,
et se retrouve ici.

## XOSHUI

`libs/css/xoshui.css`, `libs/js/xoshui.js`, `libs/fonts/`, `tools/lint.php`

Copiés depuis `D:\laragon\www\XOSHUI` — projet maison. Voir la règle 1 du
[CLAUDE.md](../CLAUDE.md) : une évolution du framework se reporte en recopiant
les fichiers, jamais en les liant.

## JetBrains Mono

`libs/fonts/webfonts/`

SIL Open Font License 1.1. Auto-hébergée : aucune requête réseau, et le voir
écrit ici évite de le redécouvrir en auditant les requêtes.

## picon

`config/icones.php` — les **tracés** de 22 icônes, extraits par
`tools/icones.php` depuis le pack [picon](https://github.com/yne/picon)
(version 21.12.05), publié sous licence libre.

Ce ne sont pas des fichiers servis : les tracés sont inlinés dans le balisage
par `src/Icone.php`. Trois raisons, et elles tiennent toutes à la charte :

- la couleur est celle du texte (`currentColor`), donc **aucun hex en dur** et
  les tokens `xo-*` colorent les icônes comme le reste ;
- aucune requête réseau, aucun fichier de plus à servir ;
- le balisage est produit par PHP, comme tout le reste (règle 2).

Pour régénérer après avoir changé la liste blanche :

```bash
php tools/icones.php [chemin/vers/picon/solid]
```

Le pack complet n'est pas conservé dans le dépôt : on n'embarque pas neuf cents
tracés pour en afficher vingt-deux.
