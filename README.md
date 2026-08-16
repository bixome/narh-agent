# NARH

Méta-agent local : une veille d'actualité qui tourne en continu, un modèle qui
répond quand on l'interroge, et — c'est le but — une boucle entre les deux. La
veille vient d'**Ekein-Scrapper**, l'agent d'**otow-agent** ; NARH est la coque
qui les fait tenir dans un seul écran.

Interface : **[XOSHUI](../XOSHUI)** en mode console (`xo-console`). PHP 8.2+ et
JavaScript vanilla — **aucun build, aucune dépendance, aucune ressource externe**.

```
┌─ Inspecteur ──┐┌─ Événements ───────────────┐┌─ Journal ─────┐
│               ││                            ││               │
│  ┌────────┐   ││       ┌───────────┐        ││  ┌───────┐    │
│  │  rien  │   ││       │  silence  │        ││  │ calme │    │
│  └────────┘   ││       └───────────┘        ││  └───────┘    │
└───────────────┘└────────────────────────────┘└───────────────┘
```

## État : P0 — la coque

Les trois écrans existent, la grammaire est posée, le linter est vert. **Rien
n'est branché derrière** : c'est voulu, et l'écran le dit lui-même plutôt que de
faire semblant.

| Phase | | |
|---|---|---|
| **P0** | La coque | ✔ fait |
| P1 | La veille — collecteur, base, arbre et fil plat | |
| P2 | La voix — Ollama, outils, flux, fils en base | |
| P3 | Le pont — interroger une ligne, remonter aux sources | |
| P4 | La boucle — conduites déclenchées, note de quart | |
| P5 | La mémoire longue — corpus, lecteur, rejeu | |

Ce que P0 démontre déjà : **une action, une porte**. Le menu « Actions », le clic
droit et la palette (`Ctrl+K`) mènent tous à la même fonction — elle répond pour
l'instant qu'elle n'est pas branchée, ce qui est exactement ce qu'on veut voir.

## Lancer

http://narh-agent.test une fois le vhost posé — voir
**[docs/deploiement.md](docs/deploiement.md)**, la règle nginx n'y est pas
facultative.

Sinon, le serveur intégré de PHP suffit :

```bash
D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -S localhost:8100 -t D:\laragon\www\narh-agent
```

## Vérifier l'interface

```bash
D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tools/lint.php
```

Aucun hex en dur, aucune classe `xo-` inexistante, aucun `data-xo-*` que le
module ne monte, aucune ressource externe. Sortie 1 s'il reste une erreur.

## Se repérer

**[CLAUDE.md](CLAUDE.md)** — la ligne de conduite : l'organisme, les neuf règles,
la forme de l'écran, les pièges hérités des deux projets d'origine. À lire avant
d'écrire une ligne.
