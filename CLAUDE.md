# NARH

Méta-agent : la veille d'**Ekein-Scrapper** et l'agent local d'**otow-agent** dans
une seule coque. Ce qui arrive déclenche ce qui pense, et ce qui pense laisse une
trace consultable.

Interface : **XOSHUI en mode console** (`D:\laragon\www\XOSHUI`). PHP 8.2+ et JS
vanilla — **aucun build, aucune dépendance, aucune ressource externe**.

Servi par Laragon (**nginx**, pas Apache) → http://narh-agent.test

**[README.md](README.md)** — ce que fait l'outil et comment le lancer.
**[docs/deploiement.md](docs/deploiement.md)** — ce qui ne doit pas être servi.
**[docs/fusion.md](docs/fusion.md)** — l'absorption des deux projets d'origine :
ce qui a été arbitré, et pourquoi, avant qu'une ligne soit écrite.

## L'organisme

NARH n'est pas un portail à deux onglets. Coller la veille à gauche et le chat à
droite donnerait deux applications qui partagent une feuille de style.

| | |
|---|---|
| **Les sens** | La collecte : ce qui arrive, en continu, sans qu'on demande. |
| **La voix** | Le modèle : ce qui répond, quand on demande. |
| **La coque** | Un écran, une grammaire, un journal. |
| **La boucle** | Ce qui manque aux deux projets d'origine : la veille déclenche l'agent, l'agent laisse une trace. C'est là, et seulement là, qu'il y a un méta-agent. |

Tant que la boucle n'existe pas, on n'a fait qu'un déménagement. Elle est donc la
cible, même si elle arrive en dernier.

## Les règles

**1 — Un hôte, une coque, un XOSHUI.** Le framework est **copié** dans `libs/`
(css, js, fonts) et dans `tools/lint.php`, pas lié : `/libs/…` de xoshui.test ne
résout pas ici. Une évolution du framework se reporte en recopiant les fichiers,
`php tools/lint.php` dans XOSHUI d'abord, `md5` des copies ensuite.

**2 — Le rendu vit en PHP.** `src/Vue.php` produit le balisage **une seule fois**,
pour l'écran au chargement et pour l'API à chaque sondage. `libs/js/narh.js` place
du HTML déjà rendu, il n'en dessine pas. Mesuré à la reprise : otow-agent dessinait
à 34 endroits en JavaScript, Ekein-Scrapper n'insérait que du rendu serveur — deux
gabarits divergent au premier ajout de colonne.

Une seule exception, et elle est de nature : le **flux de jetons** d'une réponse
reste du texte, un jeton n'ayant pas de forme. Tout ce qui a une forme — étapes
d'outil, sources, badges, lignes de journal — arrive déjà rendu.

**3 — Deux bases, une porte.** `actu.sqlite` (la collecte, WAL, écrite par le
démon) et `narh.sqlite` (fils, messages, corpus FTS5, journal). Les croisements
passent par `ATTACH` en lecture seule, dans `src/Base.php` et nulle part ailleurs.
Mélanger un écrivain qui tourne toutes les 60 s avec l'écran et le corpus, c'est
acheter des verrous qu'on ne saura pas reproduire.

**4 — La collecte ne pense pas, l'agent n'écrit pas dans la collecte.** Le score
reste lexique + reprise, seul reproductible ; ce que dit le modèle s'affiche **à
côté**, jamais dedans. L'enrichissement se lance à la main, hors cycle, et ne se
planifie pas : rien ne dépend de sa fraîcheur.

**5 — Une action, une porte.** Menu contextuel, menu « Actions », palette,
commande tapée et déclenchement automatique appellent tous `commander()`. **Ne
jamais brancher une action directement sur un bouton** : elle échapperait aux
autres chemins, et ils divergeraient.

**6 — Tout geste est une commande nommée.** Corollaire de la règle 5, et c'est lui
qui rend le méta-agent possible : une commande nommée est journalisable, rejouable
et **déclenchable par un événement**. Une conduite n'est alors qu'une commande
existante branchée sur un seuil, un mot ou une maison — aucune mécanique nouvelle
à écrire.

**7 — Une seule chronologie.** La collecte et l'agent écrivent dans le même
journal, en base. C'est le seul endroit où l'on verra « alerte à 04:30 → conduite
déclenchée → 1 200 jetons → note ». Deux journaux séparés rendraient le méta-agent
aveugle à lui-même.

**8 — Le code et l'interface sont en français** : classes, méthodes, variables,
commentaires. `$rapport['nouveaux']`, pas `$report['new']`.

**9 — Un commentaire dit pourquoi, pas quoi.** Ceux qui viennent des deux projets
d'origine marquent des pièges vérifiés — voir plus bas. Ne pas les retirer en
passant.

## L'interface

Les règles de XOSHUI s'appliquent telles quelles :

- **Aucun hex en dur.** Un token `--xo-*`, ou en ajouter un dans XOSHUI.
- Préfixe `xo-` uniquement, en BEM. **Aucune classe maison** — si un composant
  manque, il manque à XOSHUI, et c'est là qu'il faut l'ajouter.
- Les états ayant un équivalent ARIA se ciblent par attribut (`aria-selected`,
  `aria-pressed`), pas par classe.
- `border-radius: 0`, aucune ombre, aucun dégradé, monospace partout.
- Les glyphes viennent de `XOSHUI/icons.php`. Un caractère absent de JetBrains
  Mono sort de la grille.
- Comportements déclarés en HTML (`data-xo-list`, `data-xo-menu`, `data-xo-open`,
  `data-xo-palette`, `data-xo-help`, `data-xo-toast`) — pas d'appel JS à écrire.
  Après avoir inséré un fragment, lui rendre la main : `mount(cible)`.
- `--xo-faint` a un contraste < 4,5:1 : décor uniquement, jamais de texte utile.
  Même prudence avec `xo-fade`.
- `php tools/lint.php` vérifie tout cela. Sortie 1 s'il reste une erreur.

### L'écran se pilote à la souris, le clavier reste celui du framework

Ekein-Scrapper s'interdisait tout raccourci : c'est un écran de surveillance qu'on
regarde. Ici on **tape** — une console d'agent a un champ de saisie au centre.

- Ce que XOSHUI fournit déjà est acquis : `↑↓` dans les listes, `Ctrl+K` la
  palette, `?` l'aide, `Échap` les modales. `xo-keys` en bas de chaque écran.
- **Aucun raccourci maison** par-dessus : ce serait un second chemin à maintenir
  en parallèle des menus, et il divergerait.
- La palette est une **porte de plus vers `commander()`**, jamais un raccourci
  vers une action qui n'existerait qu'elle.

### La forme

**Une surface, trois bandes.** NARH n'a pas d'écrans : il a une lecture, la
conversation. Pas de barre latérale, pas d'onglets, pas de colonnes permanentes.

| Bande | Contenu | Bouge ? |
|---|---|---|
| **Barre d'état** | Une ligne : l'état des deux organes, l'heure. | Jamais |
| **En-tête** | Des tuiles **fixes** d'organisation : Veille, Alertes, Fils. On s'y repère. | Jamais |
| **Conversation** | Le fil des tours : champ, réponses, tuiles. On y travaille. | Défile en elle-même |
| **Pied** | `xo-keys`. | Jamais |

- **Le plus récent en tête, et le champ juste sous l'en-tête.** Les deux
  régimes se lisent dans le même sens : ce qui vient d'arriver est toujours
  immédiatement sous le champ. Le prix est assumé — une réponse se lit au-dessus
  de sa question, ce qui surprend une fois puis ne se remarque plus, alors que
  chercher la dernière ligne en bas d'un fil de trente tours se paie à chaque
  consultation. Un champ en bas de page obligeait en outre à faire défiler pour
  parler : le geste le plus fréquent était le plus coûteux.
- **Rien ne défile sauf la conversation.** `xo-app` est bornée à `100vh` — la
  classe du framework n'impose qu'un minimum, et la page entière se remettait à
  défiler en emportant le champ vers le bas. Or parler est le geste le plus
  fréquent : il ne doit jamais demander de faire défiler d'abord. **Le contenu
  essentiel tient à l'écran, toujours.**
- **Ce qui a disparu n'est pas perdu :** la veille, la mémoire, le journal et
  l'inspecteur sont devenus des **tuiles** (voir plus bas). Un panneau permanent
  occupe l'écran en permanence pour un contenu qu'on regarde par intermittence.
- **Deux ou trois colonnes dans la conversation.** Une tuile seule prend la
  largeur ; à plusieurs, elles se partagent la rangée. Cela se décide dans
  `Vue::tuiles()` et nulle part ailleurs — une tuile ne connaît que la largeur
  qu'elle demande, jamais ses voisines. Aucune ne dépasse le tiers de la
  hauteur : au-delà elle chasserait le champ hors de l'écran.
- **Le pont dans les deux sens.** Une ligne de veille s'interroge ; une source
  citée sous une réponse renvoie à sa ligne dans la veille.

### Deux régimes, une surface

NARH bascule entre **agent de conversation** (on demande, il répond) et **agent
en direct** (il parle sans qu'on demande). Même surface, même grammaire, même
chronologie : seul le centre change de contenu. Le régime se lit en permanence
dans la barre d'état — un agent qui parle tout seul ne doit jamais pouvoir
passer pour un agent qui attend une question.

L'antenne est une propriété de **l'agent**, pas de l'onglet : elle vit en base
(`direct_etat`). Fermer le navigateur ne coupe pas l'antenne, et deux écrans
regardent le même direct.

**La contrainte fondatrice : jamais plus de 17 secondes de blanc.** Tout le
reste en découle :

- **Aucun segment n'attend le modèle.** Un modèle local met des secondes,
  peut être déchargé ou injoignable — on ne fonde pas un direct dessus. Chaque
  segment est **composé en PHP depuis la veille** (mesuré : 30 à 45 ms). Ce que
  le modèle ajouterait est un enrichissement, jamais la source du flux.
- **On relance aux deux tiers du budget** (≈ 11 s) : le temps de composer et de
  poser doit tenir dans la marge, pas la consommer.
- **Un chien de garde** constate le dépassement et le dit à l'écran. Un direct
  qui annonce sa panne reste un direct ; un direct muet est une panne qu'on ne
  distingue pas du calme.

**Le panel** — l'ordre entre les natures *est* la conduite éditoriale :

| | |
|---|---|
| `alerte` | Niveau ≥ alerte jamais passé. **Préempte tout.** |
| `depeche` | Le sujet le plus récent jamais passé. |
| `bref` | Trois titres d'une rubrique, en rotation — local, national, thématique. |
| `point` | Une synthèse périodique. Ne consomme aucun sujet. |
| `relance` | Rien de neuf : un sujet déjà passé, repris **en le disant**. |

`relance` est ce qui rend le direct tenable : une nuit calme ne produit pas de
dépêche neuve pendant vingt minutes, et il faut pourtant parler. Sans elle, le
direct s'arrêterait au premier creux — le seul moment où il a vraiment besoin
d'exister. En dernier recours il parle de la collecte elle-même : il y a
toujours quelque chose de **vrai** à dire.

**Ne pas se répéter** tient à une mémoire d'antenne (`direct_vu`) : un sujet
passé ne repasse pas, sauf en relance, après dix minutes, et en affichant qu'il
est repris. Les lancements sont un **panel** choisi sur le rang du segment, pas
au hasard : la même formule toutes les dix-sept secondes s'entend au bout de
trois tours, et un direct rejoué doit se dérouler à l'identique.

**Fermer l'antenne produit la note de quart** — les deux livrables de la boucle
se rejoignent : ce qui vient d'être dit est exactement ce qu'il faut transmettre
à qui prend la suite. La note entre dans le fil et **son bilan est stocké, pas
recalculé** : c'est le seul contenu de NARH qui soit une photo, parce que c'est
le seul qui parle d'un moment révolu plutôt que de l'état présent.

### La tuile

Un **résultat encadré**, posé dans le fil (`src/Tuile.php`). C'est la réponse à
la question « où mettre la veille sans faire un second écran ».

- Elle apparaît **quand on la demande** (`/veille`, la palette) **ou quand
  l'agent en a besoin** : chercher dans la veille, c'est avoir besoin de la
  montrer — la réponse devient vérifiable sans changer de vue.
- Elle **entre dans la chronologie** comme une question ou une réponse : relire
  un fil, c'est revoir ce qu'on regardait au moment où on l'a demandé.
- Elle **ne stocke pas ce qu'elle montre**, seulement de quoi le refaire — un
  type et ses paramètres. Rouvrir un fil d'hier montre la veille de maintenant,
  pas une photo périmée.
- On ne demande **jamais au modèle** de choisir une tuile : il l'écorcherait
  comme il écorche les URL. Elles se déduisent de ce qu'il a réellement
  consulté.

Toute la coquille vient de `src/Ecran.php`.

### Un mot, trois états

Tout ce que NARH montre est une **pièce** (`src/Piece.php`) : une dépêche, un
événement, un tour de conversation, un fait du journal, un fil, un passage
retenu. Tous ont un instant, un acteur, un titre, une intensité — et **une seule
méthode les rend** (`Vue::ligne()`).

*Reçu* (la collecte) · *demandé* (la conversation) · *retenu* (la mémoire) ne
sont plus trois écrans mais **trois états de la même pièce**, lus au même
endroit. C'est ce qui distingue une compilation d'un déménagement : sans cette
unité, la veille et la conversation auraient chacune leur gabarit, et l'œil
devrait réapprendre à lire en passant de l'une à l'autre.

### La conversation

Le champ est la **seule entrée** de l'application, et il accepte deux choses
sans les distinguer à l'œil : une question pour le modèle, et une commande
(`/veille`). Obliger à choisir un mode avant de taper, ce serait demander de
savoir avant de commencer.

Il reste en bas de la vue, toujours visible.

`xo-timeline` porte les tours : la même grammaire que « qui d'autre en parle »
dans l'inspecteur, parce qu'une conversation est aussi une suite d'événements
datés. **Aucune classe `bulle`** : différencier qui parle passe par le glyphe du
marqueur et par l'acteur affiché, jamais par un fond de couleur ni un alignement
à droite — XOSHUI n'a pas de bulles, et lui en inventer casserait la règle 9.

- **La couleur porte quatre choses, à ne pas confondre** : `xo-tint--*` le
  marquage, `xo-fade` la fraîcheur, `xo-flash--*` la confirmation d'un geste, et
  le survol/sélection que XOSHUI gère seul. L'agent n'en ajoute pas une cinquième :
  il parle la même langue que la veille.
- **Une barre d'état, une ligne, jamais deux.**

## Les phases

| | | |
|---|---|---|
| **P0** | La coque | `bootstrap.php`, `src/Ecran.php`, `src/Vue.php`, XOSHUI copié, lint vert. ✔ |
| **P1** | La veille | Collecteur, `actu.sqlite`, arbre et fil plat sous la coque. ✔ |
| **P2** | La voix | Ollama, outils, flux SSE, fils en base, conversation. ✔ |
| **P3** | Le pont | `interroger` depuis une ligne ; sources cliquables vers la veille. ✔ |
| **P4** | La boucle | Le direct : la veille déclenche la production, l'antenne rend sa note de quart. ✔ |
| **P5** | La mémoire longue | Corpus FTS5, lecteur d'articles, rejeu du journal. |

Porter la veille avant d'écrire la boucle n'est pas de la prudence : P1 est ce qui
prouve que la coque tient sous du vrai trafic, et la boucle ne se conçoit
correctement qu'une fois le journal unique en place.

**Périmètre de la v1 : P0 → P5.** P5 était facultatif tant qu'otow-agent existait
à côté. Du moment qu'on le supprime, le lecteur et le corpus n'ont plus d'autre
domicile : sans eux la fusion serait une amputation, pas une absorption.

## Fichiers

| Chemin | Rôle |
|---|---|
| `bootstrap.php` | Constantes, autoload, réglages, `e()` |
| `config/reglages.php` | Chemins des deux bases, Ollama, cadences (`reglages.local.php` écrase) |
| `index.php` | **La** page — il n'y en a qu'une |
| `cli.php` | Le démon et les commandes d'exploitation — refuse de tourner hors `PHP_SAPI` cli |
| `api.php` | L'état de la collecte, le marquage, le cycle à la demande |
| `api/chat.php` | Le flux SSE d'une réponse — la **seule** route qui streame |
| `api/tuile.php` | Poser une tuile dans la conversation |
| `api/direct.php` | L'antenne : ouvrir, servir un segment, fermer sur la note de quart |
| `src/Direct.php` | La conduite du direct : le panel, la mémoire d'antenne, la cadence |
| `api/fils.php` | Les fils et l'état du moteur — rendus par `Vue` |
| `api/liens.php` | La passe de vérification des sources citées — après l'affichage, jamais pendant |
| `src/Ecran.php` | La coquille : barre d'état, en-tête, conversation, palette |
| `src/Tuile.php` | Le descripteur d'un résultat encadré — type et paramètres |
| `src/Vue.php` | Le rendu partagé — une seule grammaire de ligne pour toute pièce |
| `src/Piece.php` | L'unité d'affichage commune — dépêche, événement, fait, fil, tour |
| `src/Journal.php` | La chronologie unique (règle 7), en base |
| `src/Memoire.php` | Les fils et leurs tours, en base |
| `src/Agent.php` | La boucle question → (outil)* → réponse |
| `src/Ollama.php` | Le client du moteur local — cURL seul |
| `src/Outils.php` | Ce que le modèle peut appeler ; bac à sable borné à `var/bac` |
| `src/Db.php` | La connexion à `narh.sqlite`, partagée par `Journal` et `Memoire` |
| `src/Lecture.php` | Le **seul** point sortant vers le réseau ; les gardes vivent là. Le lecteur n'a pas de route à lui : c'est une **tuile**, et `api/tuile.php` est déjà sa porte (règle 5) |
| `src/Corpus.php` | Le plein texte : `passage` en FTS5, `article_lu`, au grain du paragraphe |
| `src/Ia.php` | Le second avis du modèle sur la collecte — consultatif, hors cycle |
| `src/Base.php`, `src/Collecteur.php`, `src/Flux.php`, `src/Http.php`, `src/Regroupeur.php`, `src/Alerte.php`, `src/Util.php` | Le moteur de veille, porté depuis Ekein-Scrapper |
| `libs/js/narh.js` | La porte unique côté navigateur : `commander()`, le sondage, le flux |
| `libs/{css,js,fonts}/xoshui*` | XOSHUI, **copié** depuis `D:\laragon\www\XOSHUI` |
| `tools/lint.php` | Le linter de XOSHUI, **copié** — `php tools/lint.php` |
| `var/` | Bases, verrous, bac à sable — jamais versionné, **jamais servi** |

## Règles du projet

- `declare(strict_types=1)` partout, `e()` autour de toute sortie HTML.
- PDO, requêtes préparées. Aucune concaténation de valeur dans du SQL.
- Un seul point sortant vers le réseau (`src/Lecture.php`) : les gardes contre
  les adresses privées vivent là, une fois pour toutes. Le bac à sable fichiers
  est borné à `var/bac`, sans sortie possible.
- **Aucune requête vers l'extérieur depuis le navigateur.** Pas seulement les
  assets : le lecteur récupère le texte **côté serveur** et n'en rend que les
  paragraphes. Encadrer la page d'origine aurait été plus simple, mais aurait
  chargé ses publicités et ses traceurs dans l'écran. C'est une posture, pas une
  optimisation. *(Doctrine d'otow-agent, qui ne l'avait écrite nulle part.)*
- **Une règle, un endroit.** Un seuil se décide dans la classe qui le porte ; la
  vue reçoit un résultat, elle ne refait pas le calcul. `Base::aRelancer()` pour
  « à relancer », `Base::clauses()` pour le filtre — écrit une fois, servi à
  `flux()` **et** `arbre()`, sinon changer de vue changerait ce qu'on regarde.
- Toute commande passe par `commander()` (règle 5), et se journalise là — pas dans
  chacun de ses appelants.
- **La reprise se compte en maisons, pas en flux.** Le Monde publie cinq flux,
  BFM cinq, francetvinfo six : `recalculerGroupe()` regroupe par `source.maison`
  et ignore le rang `agregateur`. Sans ce regroupement, un événement qu'une seule
  rédaction porte vaudrait déjà cinq confirmations. Ajouter une source sans lui
  donner de `maison`, c'est rouvrir le double comptage.
- **Le statut porte sur le groupe**, jamais sur la dépêche : on suit un sujet, et
  les reprises qui arrivent après en héritent. Un groupe `suivi` ou `traite`
  échappe à la rétention (`purger()`).
- **Toute source ajoutée passe par `php cli.php --verifier`** avant d'être
  retenue, et **toute retouche du lexique ou des seuils demande
  `php cli.php --rescorer`** : une dépêche n'est notée qu'à son arrivée.
- **`--enrichir-ia` ne se planifie pas.** À la main, quand on veut l'avis. Rien ne
  dépendant de sa fraîcheur, une tâche répétée ne serait qu'un processus de fond
  qu'on oublie et dont on ne lit jamais la sortie. Ne pas proposer de `schtasks`,
  de cron ni de boucle pour cette commande.
- **La lecture d'article se fait hors réponse.** Lire un article coûte une à deux
  secondes ; cinq articles, c'est dix secondes ajoutées à une question, au moment
  précis où l'on attend. Le corpus se remplit à part (`cli.php --ingerer`), la
  recherche ne fait que le consulter. Même raison pour la vérification des liens :
  l'écran affiche d'abord, **puis** estompe ce qui ne répond pas.
- **Le grain du corpus est le paragraphe, pas l'article** — c'est ce qui permet de
  donner au modèle le passage utile plutôt que trois pages.

## Pièges déjà rencontrés

Ils ont été payés dans les deux projets d'origine. Ils se transfèrent avec le code.

- **`curl_multi`** : sortir de la boucle sur `$actives === 0` donne un lot
  incomplet et silencieux (code 0, errno 0). Compter les `CURLMSG_DONE`.
- **Groupes orphelins** : ouvrir un événement avant de savoir si la dépêche entre
  en base en laisse un derrière chaque doublon. Interroger `connu()` d'abord.
- **Flux sans date** : celui du Parisien ne transporte que titre et lien. Les dater
  à l'heure du relevé met cent entrées du même journal en tête du fil. Le rang dans
  le flux est le seul signal de fraîcheur disponible.
- **Titres d'agrégateur** : Google Actualités suffixe « - Le Monde.fr ». Le suffixe
  fausse le rapprochement ; l'élément RSS `<source>` donne de quoi le retirer.
- **`strip_tags`** sur un résumé d'agrégateur recolle les mots. Remplacer chaque
  balise par une espace.
- **`str_pad`** compte des octets : en CLI, chaque accent décale la colonne.
- **Le fuseau** : sans `date_default_timezone_set`, PHP retombe sur l'UTC de l'ini
  et tout l'horodatage dérive de deux heures par rapport à l'écran.
- **Une ligne de journal multiligne** casse l'entrée en deux : mettre le message à
  plat avant de l'écrire.
- **Les assets sans empreinte** restent en cache après correction : le projet n'a
  pas d'étape de build, `filemtime` en tient lieu.
- **Le module du framework importé deux fois** : le `<script>` de la page charge
  `xoshui.js?v=…`, et un `import './xoshui.js'` nu est une **autre URL**, donc un
  autre module, avec son propre registre de montage. Chaque liste, chaque menu et
  chaque notification se retrouvent alors avec deux jeux de gestionnaires — un
  seul `Entrée` dans la palette déclenchait deux fois la commande. `narh.js`
  reprend donc la version depuis `import.meta.url`. *(Ekein-Scrapper porte la même
  ligne fautive dans `libs/js/app.js`.)*
- **La palette ne s'active qu'au clavier** : XOSHUI n'émet `xo:activate` que sur
  `Entrée`. Un clic sélectionne la ligne et referme la boîte sans rien exécuter —
  `narh.js` branche donc aussi le clic.
- **Un warning PHP au milieu d'un flux SSE** casse la trame et le client cesse de
  lire : `display_errors` à 0 sur ces routes, `log_errors` à 1.
- **Un octet non-UTF8** venu d'un fichier lu par un outil fait échouer
  `json_encode()` et perd la trame entière : `JSON_INVALID_UTF8_SUBSTITUTE`.
  Mesuré ici : la même faute côté **requête sortante** tuait la conversation
  avant le premier jeton (« Malformed UTF-8 characters ») quand un client
  postait en Latin-1. `Ollama` substitue au lieu d'échouer, et `api/chat.php`
  répare l'entrée à la frontière — une fois, au bon endroit.
- **Le modèle appelle un outil par réflexe** dès qu'on lui en présente un, même
  sur un « merci » : `Agent::outilsPour()` ne les propose pas sous douze
  caractères, et `outils_auto` permet de les couper tout à fait.
- **Un résultat d'outil rejoué à chaque tour** maintient le sujet chaud : le
  modèle y revient sans qu'on demande rien. Il sort du contexte une fois
  consommé (`Memoire::fermerOutils()`), tout en restant affiché.

## Ce qu'on ne recopie pas

- **Le dessin en JavaScript** d'otow-agent (règle 2).
- **La conversation en `$_SESSION`** : tout vit en base, la session ne garde qu'un
  pointeur — fermer l'onglet ne doit rien perdre.
- **Le journal en fichier tabulé** : une seule chronologie, en base (règle 7).
- **Les réglages en JSON à côté d'une base** : `config/reglages.php` pour ce qui
  doit se lire sans PHP ni base, le reste en base.
- **Les doubles copies** de XOSHUI, des polices et du favicon : une seule ici.
- **Le CSS maison** d'otow-agent : 12,7 Ko d'`assets/otow.css` chargés par-dessus
  la feuille du framework. Ce qui manque manque à XOSHUI (règle 1). NARH n'a
  aucun fichier CSS propre, et n'en aura pas.
- **La méta-cognition** d'otow-agent — vraisemblance des jetons, ancrage,
  `souligner_doutes`. Renoncement assumé, pas oubli de portage : un modèle local
  qui commente sa propre confiance produit une seconde voix qu'on ne sait ni
  vérifier ni reproduire, à côté d'un score lexical qui, lui, l'est. Ne pas la
  réintroduire en croyant compléter le portage.
