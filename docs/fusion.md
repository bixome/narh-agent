# La fusion — l'arbitrage

Ekein-Scrapper et otow-agent disparaissent dans NARH. Ce document tranche ce qui
diverge, **avant qu'une ligne soit écrite** : une règle qu'on arbitre en codant
s'arbitre en faveur de ce qui est le plus court à taper, et le perdant n'est
jamais celui qu'on aurait choisi à froid.

Tout ce qui suit est mesuré, pas supposé. Les mesures sont datées du 16/08/2026.

---

## I — Les règles

### 1. Ce qui est déjà tranché

Ces divergences ont été résolues pendant P0→P4. Elles sont ici pour **ne pas
être rejouées** au moment de recopier le code d'origine.

| Divergence | Ekein / otow | NARH tranche |
|---|---|---|
| Clavier | Ekein : « aucun raccourci maison, pas de `xo-keys`, pas de palette » | NARH **impose** la palette et `xo-keys`. Une console d'agent a un champ de saisie ; un écran de surveillance n'en a pas. |
| Rendu | otow dessinait à 34 endroits en JavaScript | Règle 2 : le balisage vit en PHP, une seule fois. |
| Conversation | otow : `$_SESSION` | Tout en base, la session ne garde qu'un pointeur. |
| Réglages | otow : `data/reglages.json` à côté d'une base | `config/reglages.php` pour ce qui se lit sans PHP ni base, le reste en base. |
| Écrans | Ekein : deux écrans (arbre, fil plat) ; otow : `index.php` + `admin.php` + `reglages.php` | Une surface, trois bandes. |

**Conséquence pratique :** en recopiant `cli.php`, ne pas réintroduire son
journal en fichier ; en recopiant `api/lecteur.php`, ne pas réintroduire son
rendu JavaScript. Ce sont les deux endroits où la recopie mécanique trahirait.

### 2. Les règles vivantes d'Ekein, absentes de NARH

Elles pilotent du code **déjà porté dans NARH**, mais ne sont écrites nulle
part ici. C'est le vrai trou de doctrine : le code applique une règle que
`CLAUDE.md` ne connaît pas.

| Règle | Où elle vit dans NARH | À faire |
|---|---|---|
| **La reprise se compte en maisons, pas en flux.** Le Monde publie cinq flux, BFM cinq : sans regroupement par `source.maison`, un événement qu'une seule rédaction porte vaudrait cinq confirmations. Ajouter une source sans `maison`, c'est rouvrir le double comptage. | `Base.php:719` — `GROUP BY CASE WHEN s.maison = '' THEN s.id ELSE s.maison END` | **Inscrire.** Le code est là, la règle non. |
| **Le statut porte sur le groupe, jamais sur la dépêche.** On suit un sujet ; les reprises qui arrivent ensuite en héritent. Un groupe `suivi` ou `traite` échappe à `purger()`. | `Base::recalculerGroupe()`, `purger()` | **Inscrire.** |
| **Toute source ajoutée passe par `--verifier`** avant d'être retenue. | — | Inapplicable tant que `cli.php` n'existe pas. **Inscrire avec lui.** |
| **Toute retouche du lexique ou des seuils demande `--rescorer`** : une dépêche n'est notée qu'à son arrivée. | — | Idem. |
| **`--enrichir-ia` ne se planifie pas.** Ne pas proposer de `schtasks`, de cron ni de boucle : rien ne dépend de sa fraîcheur, une tâche répétée ne serait qu'un processus de fond qu'on oublie. | Règle 4, à moitié | **Compléter.** NARH dit « se lance à la main » sans l'interdiction explicite. |

### 3. La collision « journal » — le seul arbitrage réel

NARH porte **deux choses différentes sous ce nom** :

| | |
|---|---|
| `Journal::noter()` / `lire()` | La chronologie unique de la règle 7, dans `narh.sqlite`. **Vivante** : une vingtaine d'appelants — la collecte, le direct, l'agent, les tuiles, les réglages. |
| `Base::journal()` — `src/Base.php:1062` | Le journal **dérivé de la collecte**, hérité d'Ekein : saillances calculées à la volée, rationnées par catégorie. **Aucun appelant.** Son rendu `Vue::journal()` n'a pas été porté — `Vue::activite()` l'a remplacé, mais celui-ci sert `Journal::lire()`, pas ceci. Le commentaire de `Base.php:1058` cite encore un `Vue::journal()` qui n'existe plus. |

C'est du code mort qui porte le nom du concept central du projet. Laissé tel
quel, il fera croire au prochain lecteur que la règle 7 est déjà branchée sur la
collecte, alors qu'elle ne l'est pas.

**Ce qui se perdrait en le supprimant** est une règle mesurée chez Ekein, et
elle est bonne : *le journal rationne par catégorie, jamais globalement*. La
marge du signal faible remonte des dizaines d'événements limitrophes par jour —
souvent des scores sportifs « en direct », faux positifs du lexique — de quoi
noyer les deux ou trois grosses actualités de la même fenêtre si tout partageait
un seul plafond.

**Arbitrage — fait.** Garder le calcul, lui rendre son nom, et le brancher sur la
chronologie unique.

1. `Base::journal()` → **`Base::saillances()`**. Un nom pour une chose : ce sont
   les faits saillants de la collecte, pas une chronologie.
2. Le cycle (dans `cli.php` et `Collecteur`) appelle `saillances()` et **verse
   le résultat dans `Journal::noter()`**. La règle 7 cesse d'être une intention.
3. Le rationnement par catégorie survit là où il était : dans `saillances()`,
   qui décide combien de chaque nature méritent une ligne.

C'est ce qui rend visible la phrase de `CLAUDE.md` : « alerte à 04:30 → conduite
déclenchée → 1 200 jetons → note ». Le premier maillon manquait.

Vérifié à l'exécution — `alerte ×2 : Rixe à Compiègne` et `direct : depeche`
dans la même chronologie, à la même seconde :

```
20:23:17  warn   actu     alerte ×2 : Rixe à Compiègne : l'homme gravement…
20:23:17  ok     collecte cycle à la main : 59 source(s), 3 neuve(s)
20:23:15  info   direct   depeche : Lens - PSG : débuts de Digne, Barcola…
```

Deux points d'implémentation qui ne se devinaient pas depuis l'arbitrage :

- **`journaliserCycle()` prend désormais la `Base`.** Quatre portes déclenchent
  un cycle (l'écran, deux chemins d'`api.php`, le démon) ; ajouter un second
  appel à chacune aurait garanti qu'une cinquième l'oublie. Le paramètre force
  le passage par la porte unique.
- **Un repère (`meta.saillances_vu`)**, sans quoi une grosse actu — saillante
  pendant des heures — serait notée à chaque cycle, soit le même titre toutes
  les soixante secondes. Au tout premier passage le repère se pose sans rien
  écrire : installer le démon sur une base déjà remplie déverserait sinon
  quarante lignes d'un coup.

### 4. La doctrine d'otow, qui n'a jamais été écrite

**otow-agent n'a ni `README.md` ni `CLAUDE.md` ni `docs/`.** Ses règles vivent
uniquement dans les en-têtes de fichiers. Supprimer le dossier sans les extraire
les perd — c'est la partie la plus fragile de la fusion.

À promouvoir dans `CLAUDE.md` :

- **Aucune requête vers l'extérieur depuis le navigateur.** NARH écrit déjà
  « aucune ressource externe », ce qui ne couvre que les assets. otow allait plus
  loin : le lecteur récupère le texte **côté serveur** et n'en rend que les
  paragraphes, précisément pour ne pas charger les publicités et les traceurs de
  la page d'origine dans l'écran. C'est une posture, pas une optimisation.
- **Un seul point sortant, et ses gardes vivent là.** Déjà annoncé pour
  `src/Lecture.php` en P5 — le confirmer plutôt que le redécouvrir.
- **Vérifier les liens après l'affichage, jamais pendant.** Vérifier une source
  pendant la réponse ajoute une demi-seconde par lien au moment le plus
  sensible. L'écran affiche d'abord, puis estompe ce qui ne répond pas.
- **L'ingestion se fait hors réponse.** Lire un article coûte une à deux
  secondes ; cinq articles, c'est dix secondes ajoutées à une question, au moment
  précis où l'on attend. Le corpus se remplit à part ; la recherche ne fait que
  le consulter.
- **Le grain du corpus est le paragraphe, pas l'article.** C'est ce qui permet de
  donner au modèle le passage utile plutôt que trois pages.

### 5. Ce qu'on abandonne, et qu'on écrit pour ne pas y revenir

| Abandonné | Pourquoi |
|---|---|
| La **méta-cognition** d'otow (vraisemblance des jetons, ancrage, `souligner_doutes`) | Décision déjà prise (`src/Agent.php:13`). L'inscrire comme un renoncement assumé, pas comme un oubli de portage. |
| `fil.php` — le fil plat d'Ekein | Une surface, pas deux écrans. |
| `admin.php` + `reglages.php` d'otow (15 Ko de deux écrans) | `api/reglages.php` fait la même chose depuis la console. |
| `data/journal.log` — le journal en fichier d'otow | Le format que la règle 7 rejette. |

---

## II — Les données

### 1. La collecte : remplacement, pas fusion

Mesuré sur les deux `actu.sqlite` :

| | Ekein | NARH |
|---|---|---|
| Articles | 9 990 | 4 397 |
| Groupes | 7 604 | 3 512 |
| Sources | 59 | 59 |
| Fenêtre | 20/07 05:44 → 16/08 17:52 | 20/07 05:44 → 16/08 17:53 |

Croisement sur `article.cle` (la clé de dédoublonnage, `UNIQUE`) :

```
communs                 4 394
seulement dans NARH         3
seulement dans Ekein    5 597
```

La collecte de NARH est un **sous-ensemble** de celle d'Ekein, sur la même
fenêtre et les mêmes sources. Trois articles la distinguent. La différence
n'est pas une divergence de contenu, c'est une différence de densité — et elle
s'explique : sans démon, NARH ne relève que lorsqu'un navigateur est ouvert.

`config/sources.php` est **identique octet pour octet** dans les deux projets
(md5 `9b1037d108e72cdf10858eae75fa3ca0`). Aucune dérive de source à réconcilier.

**Donc : remplacer le fichier.** Un script de fusion avec remap des `groupe_id`
serait une demi-journée de travail pour récupérer trois articles.

Un seul point d'attention, et il ne porte pas sur les articles : **6 groupes
sont marqués** (`statut` non vide) dans la base de NARH, 16 dans celle d'Ekein.
Un marquage est une décision humaine — ça vaut plus que trois dépêches. Les
six se ré-appliquent en les retrouvant par `article.cle`.

**Fait.** Les deux bases étant écrites pendant l'opération — un onglet ouvert sur
chaque hôte suffit à déclencher `cycle_auto` —, ni l'arrêt des cycles ni la copie
de fichier n'étaient praticables. La reprise est donc passée par un `VACUUM INTO`
(un instantané cohérent d'une base vivante) puis par **une transaction** sur la
base de NARH : un cycle concurrent se place avant ou après, jamais au milieu.

```
articles 4 442 → 10 034 · groupes 3 541 → 7 632 · sources 59 → 59
8 statuts ré-appliqués, 0 introuvable
intégrité ok · clés étrangères saines
```

**Le piège, et il ne se voyait pas dans le code :** `source` et `groupe` portent
les mêmes colonnes des deux côtés, **mais pas dans le même ordre**. `maison` et
`rang` sont en 7ᵉ et 8ᵉ position chez NARH, en avant-dernières chez Ekein, qui
les a ajoutées par migration après coup. `Base.php` est identique dans les deux
projets — la divergence n'existe que dans les fichiers `.sqlite`, produits à des
moments différents de l'histoire du schéma.

Un `INSERT … SELECT *` aurait donc rangé la maison dans `actif` et le rang dans
`etag`, sur les 59 sources, sans une seule erreur. Les colonnes se nomment une
par une, et un contrôle compare les **ensembles** de colonnes avant d'écrire.

C'est la leçon transférable de toute l'étape : deux schémas identiques en code ne
garantissent pas deux tables identiques en base.

Les statuts se sont tous retrouvés par la `cle` de leurs articles — 8, et non 6
comme mesuré une heure plus tôt : la collecte tournait entre-temps.

### 2. Les fils : reprise directe

Les schémas sont les mêmes à un renommage près :

| otow — `conversation` / `message` | NARH — `fil` / `message` |
|---|---|
| `conversation(id, titre, debut, maj)` | `fil(id, titre, debut, maj)` — **identique** |
| `message(id, conversation_id, role, contenu, quand, heure, etapes, bilan, consomme)` | `message(id, fil_id, …, jetons, consomme)` — **identique + `jetons`** |

Reprise par `ATTACH` puis `INSERT … SELECT`, avec remap des identifiants
(`narh.sqlite` a déjà ses propres fils) et `jetons` à 0 pour l'historique — la
valeur n'a jamais été mesurée chez otow, l'inventer serait pire que l'avouer.

**Fait.**

```
5 fils, 24 messages repris · total NARH : 26 fils, 173 messages
2 fichiers de bac à sable repris dans var/bac
intégrité ok · clés étrangères saines
```

Le volume est dérisoire à côté du corpus (4 083 passages, 542 articles lus),
mais c'est la partie qu'aucune ingestion ne saurait refaire : ce sont des
questions posées par quelqu'un.

### 3. Le corpus : reprise différée

`passage` (FTS5) et `article_lu` **n'existent pas encore** dans `narh.sqlite` :
ce sont les tables de P5. Les schémas se recopient mot pour mot depuis
`otow-agent/lib/base.php`. La reprise des lignes se fait **après** que P5 les
ait créées, pas avant.

**P5 est écrit** — `src/Lecture.php`, `src/Corpus.php`, `api/liens.php`,
`cli.php --ingerer`, quatre outils de plus. Trois choses en sont ressorties qui
ne se devinaient pas depuis l'arbitrage :

**Le lecteur n'a pas de route à lui, et c'est le résultat le plus net.** otow
avait `api/lecteur.php` parce qu'il avait un écran de lecture. NARH n'a pas
d'écran : il a des tuiles. `Tuile::LECTURE` passe par `api/tuile.php`, déjà la
porte de toutes les tuiles — une route dédiée aurait été un second chemin vers
la même chose (règle 5). Même raisonnement pour le corpus.

**Deux constantes attendaient P5 sans qu'on l'ait su en écrivant l'arbitrage :**
`Piece::PASSAGE` et son glyphe `¶` dans `Vue::GLYPHES`. Un passage se rend donc
par `Vue::ligne()` comme une dépêche ou un tour — c'est le troisième état de la
pièce, *retenu*, et l'œil n'a rien à réapprendre. La ligne porte l'extrait comme
titre, la maison comme acteur, le titre de l'article en méta : dans une liste,
ce qu'on vient lire est le texte, pas le titre qu'on avait déjà.

**Une limite mesurée, à ne pas prendre pour un corpus propre :** l'extracteur
retient tout `<p>` de plus de 80 caractères qui n'est pas de l'habillage. Sur
une page qui mêle un article et un bloc de recirculation — franceinfo le fait —
des paragraphes hors sujet entrent au nom du mauvais article. Vérifié à
l'ingestion : un passage sur une médaille de bronze rangé sous « Une pétition
recueille 30 000 signatures ». Le texte est vrai, son attribution ne l'est pas.
Le filtre d'habillage ne peut rien contre ça : ce n'est pas de l'habillage, c'est
un autre article. À traiter par un ancrage sur le conteneur de l'article, pas par
un motif de plus.

**La reprise est faite.**

```
article_lu   542 repris, 0 déjà connus ici
passages   4 083 repris
corpus       342 articles · 4 123 passages · 203 illisibles
```

Les 203 illisibles ne sont pas un échec de la reprise : ce sont des murs payants
et des pages mortes qu'otow avait déjà rencontrés, et les garder évite de les
ré-essayer un par un. C'est précisément ce à quoi sert `article_lu`.

Contrôle : une recherche sur le corpus repris (« gouvernement budget ») rend
trois passages classés. L'index FTS5 se reconstruit bien à l'insertion — mieux
valait le vérifier que le supposer.

### 4. Les fichiers hors base

| Fichier otow | Destination |
|---|---|
| `data/reglages.json` | **Rien à reprendre, vérifié.** `modele` (`llama3.2:3b`) et `temperature` (0.7) sont déjà ceux de NARH ; `outils_auto` a le même défaut ; le `prompt_systeme` de NARH parle de veille et de console, celui d'otow d'« un agent local intégré à une console web » — le reprendre serait régresser. `ekein_db` est caduc, `metacognition` et `souligner_doutes` sont un renoncement assumé. |
| `data/liens.json` | **Devient une table**, pas un JSON — même raison que les réglages. Clé md5 du lien, `ok`, `quand`. |
| `data/journal.log` | Abandonné. |
| `data/sandbox/` | `var/bac`. |

---

## III — L'ordre

L'arbitrage impose une contrainte que la seule lecture du code ne donnait pas :
**la reprise de la collecte passe avant `cli.php`**, pour que le démon démarre
sur la bonne base plutôt que d'avoir à la remplacer sous lui.

1. Ce document.
2. `CLAUDE.md` mis à jour — voir plus bas.
3. Reprise d'`actu.sqlite`.
4. `cli.php`, puis `src/Ia.php`. `Base::journal()` → `Base::saillances()`,
   branché sur `Journal`.
5. Reprise des fils d'otow.
6. P5 — `Lecture.php`, le corpus, l'ingestion, les liens, les quatre outils.
7. Reprise du corpus.
8. Suppression des deux dossiers et de leurs deux vhosts nginx.

## Ce que ça change dans `CLAUDE.md`

- **Règles du projet** : ajouter *la reprise se compte en maisons*, *le statut
  porte sur le groupe*, `--verifier`, `--rescorer`, et l'interdiction de
  planifier `--enrichir-ia`.
- **Interface** : ajouter *aucune requête sortante depuis le navigateur*.
- **P5** : ajouter *vérifier les liens après affichage*, *l'ingestion hors
  réponse*, *le grain est le paragraphe*.
- **Ce qu'on ne recopie pas** : ajouter la méta-cognition d'otow.
- **Fichiers** : `cli.php`, `src/Ia.php`, `src/Lecture.php`, `src/Corpus.php`.
- `NARH_PHASE` (`bootstrap.php:24`) est resté à `P0`, et `README.md` annonce
  encore « rien n'est branché derrière ». Les deux datent d'avant P1.
