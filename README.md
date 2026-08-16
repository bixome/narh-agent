# NARH

Méta-agent local : une veille d'actualité qui tourne en continu, un modèle qui
répond quand on l'interroge, et — c'est le but — une boucle entre les deux. La
veille vient d'**Ekein-Scrapper**, l'agent d'**otow-agent** ; NARH est la coque
qui les fait tenir dans un seul écran, et qui les remplace.

Interface : **[XOSHUI](../XOSHUI)** en mode console (`xo-console`). PHP 8.2+ et
JavaScript vanilla — **aucun build, aucune dépendance, aucune ressource externe**.

```
┌──────────────────────────────────────────────────────────────┐
│ veille · 59 flux    agent · llama3.2:3b            18:42:07   │  barre d'état
├──────────────────────────────────────────────────────────────┤
│  ┌─ Veille ──────┐  ┌─ Alertes ─────┐  ┌─ Fils ─────────┐    │  en-tête
│  │ 9 990 dépêches│  │ 3 en cours    │  │ 12 fils        │    │
│  └───────────────┘  └───────────────┘  └────────────────┘    │
├──────────────────────────────────────────────────────────────┤
│  > _                                                          │  le champ
│                                                               │
│  ▸ 18:41  agent    la réponse la plus récente, en tête        │  conversation
│  ▸ 18:41  vous     la question qui l'a produite               │  (défile)
│  ▸ 18:39  veille   ┌─ tuile ────────────────────────┐         │
│                    │ ce qu'on regardait à ce moment │         │
│                    └────────────────────────────────┘         │
├──────────────────────────────────────────────────────────────┤
│ ↑↓ parcourir   Ctrl+K palette   ? aide   Échap fermer         │  xo-keys
└──────────────────────────────────────────────────────────────┘
```

Une surface, trois bandes. Le plus récent en tête, le champ juste sous
l'en-tête : ce qui vient d'arriver est toujours immédiatement sous le curseur, et
parler ne demande jamais de faire défiler d'abord.

## État

| Phase | | |
|---|---|---|
| **P0** | La coque — barre d'état, en-tête, conversation, palette | ✔ |
| **P1** | La veille — collecteur, `actu.sqlite`, arbre et fil plat | ✔ |
| **P2** | La voix — Ollama, outils, flux SSE, fils en base | ✔ |
| **P3** | Le pont — interroger une ligne, remonter aux sources | ✔ |
| **P4** | La boucle — le direct, la note de quart | ✔ |
| **P5** | La mémoire longue — corpus FTS5, lecteur, liens | ✔ |

**La fusion est faite.** Ekein-Scrapper et otow-agent ont été absorbés puis
supprimés : NARH ne dépend plus que de [XOSHUI](../XOSHUI), et encore, par copie.
Ce qui a été arbitré avant d'écrire une ligne, et ce que la reprise a fait
apparaître, est dans **[docs/fusion.md](docs/fusion.md)**.

| | |
|---|---|
| Collecte | 10 124 dépêches · 7 673 événements · 59 sources |
| Corpus | 342 articles lus · 4 123 passages |
| Conversation | 26 fils · 173 tours |

## Lancer

http://narh-agent.test une fois le vhost posé — voir
**[docs/deploiement.md](docs/deploiement.md)**, la règle nginx n'y est pas
facultative.

Sinon, le serveur intégré de PHP suffit :

```bash
D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -S localhost:8100 -t D:\laragon\www\narh-agent
```

## La veille en fond

Sans démon, la collecte ne relève que lorsqu'un onglet est ouvert — mesuré au
moment de la fusion : 4 397 dépêches contre 9 990 pour la même fenêtre et les
mêmes sources.

```bash
D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe cli.php --veille
```

Mettre alors `collecte_web` à `false` dans `config/reglages.local.php` : l'écran
devient un simple lecteur de la base, et répond en quelques millisecondes.

`--une-fois` pour un seul relevé, `--etat` pour l'état des sources, `--verifier`
après avoir ajouté un flux, `--rescorer` après avoir touché au lexique.

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
