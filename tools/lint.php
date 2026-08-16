<?php
declare(strict_types=1);

/**
 * xoshui-lint — vérifie les règles du framework qu'on ne peut pas se contenter
 * de mémoriser : aucun hex en dur, aucun arrondi, aucune ombre, aucune
 * ressource externe, aucun token ni classe qui n'existe pas.
 *
 *   php tools/lint.php            à la racine du projet
 *   php tools/lint.php --quiet    n'affiche que les manquements
 *
 * Sortie : 0 si tout va bien, 1 s'il reste au moins une erreur.
 * En navigateur, la même analyse s'affiche avec les styles du framework.
 *
 * Échappatoire : une ligne portant « xo-lint-ignore » n'est pas analysée.
 */

const ROOT = __DIR__ . '/../';

/** Fichier de référence : les hex, arrondis et dégradés n'y sont pas des fautes. */
const SOURCE = 'libs/css/xoshui.css';

/** Le linter contient les motifs qu'il traque : il ne s'analyse pas lui-même. */
const SELF = 'tools/lint.php';

const EXTENSIONS = ['php', 'css', 'js', 'html'];

/** Dossiers hors périmètre. `docs/` contient des hex en exemple, c'est légitime. */
const EXCLUS = ['.git', 'moodboard', 'docs', 'vendor', 'node_modules', '.cache'];

/** Comportements que le module JS sait monter. */
const HOOKS = ['list', 'tabs', 'open', 'close', 'palette', 'help', 'split', 'toast', 'tip',
               'timer', 'key', 'guard', 'guard-ok', 'menu'];

/* ---------------------------------------------------------------- Collecte */

/** @return list<string> chemins relatifs */
function fichiers(): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                return !$f->isDir() || !in_array($f->getFilename(), EXCLUS, true);
            },
        ),
    );

    // realpath des deux côtés : sans quoi le préfixe « tools/../ » reste collé
    // au chemin et aucune comparaison de fichier ne fonctionne.
    $racine = str_replace('\\', '/', (string) realpath(ROOT)) . '/';

    foreach ($it as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), EXTENSIONS, true)) {
            continue;
        }
        $chemin = str_replace('\\', '/', (string) $f->getRealPath());
        $relatif = str_starts_with($chemin, $racine) ? substr($chemin, strlen($racine)) : $chemin;
        if ($relatif !== SELF) {
            $out[] = $relatif;
        }
    }

    sort($out);
    return $out;
}

/**
 * Tokens et classes déclarés dans la feuille de référence.
 *
 * @return array{tokens: array<string,true>, classes: array<string,true>}
 */
function vocabulaire(): array
{
    $css = (string) file_get_contents(ROOT . SOURCE);

    preg_match_all('/(--xo-[a-z0-9-]+)\s*:/i', $css, $t);
    preg_match_all('/\.(xo-[a-z0-9_-]+)/i', $css, $c);

    return [
        'tokens'  => array_fill_keys($t[1], true),
        'classes' => array_fill_keys($c[1], true),
    ];
}

/* ----------------------------------------------------------------- Analyse */

/**
 * @return list<array{fichier:string, ligne:int, niveau:string, regle:string, message:string}>
 */
function analyser(string $fichier, array $vocab): array
{
    $lignes  = file(ROOT . $fichier, FILE_IGNORE_NEW_LINES);
    $estCss  = str_ends_with($fichier, '.css');
    $estRef  = $fichier === SOURCE;
    $probs   = [];

    $ajoute = static function (int $n, string $niveau, string $regle, string $msg) use (&$probs, $fichier): void {
        $probs[] = [
            'fichier' => $fichier, 'ligne' => $n + 1,
            'niveau'  => $niveau, 'regle' => $regle, 'message' => $msg,
        ];
    };

    foreach ($lignes as $n => $ligne) {
        if (str_contains($ligne, 'xo-lint-ignore')) {
            continue;
        }

        // --- Couleurs en dur. Hors CSS, on n'inspecte que les lignes qui
        // déclarent du style : « #412 » dans un texte est un numéro de ticket,
        // pas une couleur — et les ancres (href="#section") non plus.
        $ligneStyle = $estCss || str_contains($ligne, 'style=') || str_contains($ligne, '<style');
        if (!$estRef && $ligneStyle
            && preg_match_all('/(?<![\w=]"|\'|&)#([0-9a-f]{3,8})\b/i', $ligne, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $len = strlen($hit[1]);
                if (in_array($len, [3, 4, 6, 8], true)) {
                    $ajoute($n, 'error', 'hex',
                        "couleur en dur #{$hit[1]} — utiliser un token --xo-*, ou en ajouter un dans " . SOURCE);
                }
            }
        }

        // --- Formes interdites par la charte.
        // Comparaison après extraction : une négation dans le motif se laisse
        // contourner par le retour arrière du moteur sur \s*.
        if (preg_match('/border-radius\s*:\s*([^;}]+)/i', $ligne, $m)) {
            $valeur = trim($m[1]);
            if ($valeur !== '0' && $valeur !== 'var(--xo-radius)') {
                $ajoute($n, 'error', 'radius', "border-radius: {$valeur} — la charte impose 0");
            }
        }
        if (preg_match('/\b(box|text)-shadow\s*:\s*(?!none)([^;}]+)/i', $ligne, $m)) {
            $ajoute($n, 'error', 'ombre', $m[1] . '-shadow — aucune ombre dans le framework');
        }
        if (preg_match('/\b(?<!repeating-)(linear|radial|conic)-gradient/i', $ligne, $m)) {
            $ajoute($n, 'error', 'degrade', $m[1] . '-gradient — aucun dégradé');
        }

        // --- Ressources externes : le framework doit rester autonome.
        if (preg_match('#\b(?:href|src)\s*=\s*["\']https?://#i', $ligne)
            || preg_match('#@import\s+(?:url\()?["\']?https?://#i', $ligne)) {
            $ajoute($n, 'error', 'externe', 'ressource externe — aucune dépendance réseau');
        }

        // --- Tokens inconnus. Avec valeur de repli, c'est un réglage local, pas une faute.
        if (preg_match_all('/var\(\s*(--xo-[a-z0-9-]+)\s*(,)?/i', $ligne, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (!isset($vocab['tokens'][$hit[1]]) && !isset($hit[2])) {
                    $ajoute($n, 'error', 'token',
                        "{$hit[1]} n'est déclaré nulle part — le déclarer, ou prévoir un repli var({$hit[1]}, …)");
                }
            }
        }

        // --- !important : toléré, mais il doit rester une exception justifiée.
        if (str_contains($ligne, '!important')) {
            $ajoute($n, 'warn', 'important',
                '!important — justifier par un commentaire xo-lint-ignore, ou augmenter la spécificité');
        }

        // --- Une police imposée localement contredit la feuille unique.
        if (!$estRef && preg_match('/font-family\s*:/i', $ligne)) {
            $ajoute($n, 'warn', 'police', 'font-family en dur — utiliser var(--xo-font)');
        }

        if ($estCss) {
            continue;
        }

        // --- Classes xo- qui n'existent pas : presque toujours une coquille.
        $sansPhp = preg_replace('/<\?.*?\?>/s', '', $ligne) ?? $ligne;
        if (preg_match_all('/class\s*=\s*"([^"]*)"/i', $sansPhp, $m)) {
            foreach ($m[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $classe) {
                    // Un nom tronqué (« xo-progress-- ») est le reste d'une
                    // expression PHP retirée juste avant : ce n'est pas une faute.
                    if ($classe === '' || !str_starts_with($classe, 'xo-')
                        || str_ends_with($classe, '-') || str_ends_with($classe, '_')) {
                        continue;
                    }
                    if (!isset($vocab['classes'][$classe])) {
                        $ajoute($n, 'error', 'classe', "{$classe} n'existe pas dans " . SOURCE);
                    }
                }
            }
        }

        // --- Comportements que personne ne monte.
        if (preg_match_all('/data-xo-([a-z-]+)/i', $ligne, $m)) {
            foreach ($m[1] as $hook) {
                if (!in_array(strtolower($hook), HOOKS, true)) {
                    $ajoute($n, 'error', 'hook',
                        "data-xo-{$hook} n'est monté par aucun comportement de xoshui.js");
                }
            }
        }
    }

    return $probs;
}

/* ------------------------------------------------------------------ Rapport */

$fichiers = fichiers();
$vocab    = vocabulaire();
$problemes = [];

foreach ($fichiers as $f) {
    array_push($problemes, ...analyser($f, $vocab));
}

$erreurs = array_filter($problemes, static fn (array $p): bool => $p['niveau'] === 'error');
$alertes = array_filter($problemes, static fn (array $p): bool => $p['niveau'] === 'warn');
$touches = count(array_unique(array_column($problemes, 'fichier')));

/* --- Sortie terminal ---------------------------------------------------- */

if (PHP_SAPI === 'cli') {
    $quiet = in_array('--quiet', $argv ?? [], true);
    $c = static fn (string $code, string $s): string => "\033[{$code}m{$s}\033[0m";

    if (!$quiet) {
        echo $c('1', 'xoshui-lint') . ' — ' . count($fichiers) . " fichiers analysés\n\n";
    }

    $courant = null;
    foreach ($problemes as $p) {
        if ($p['fichier'] !== $courant) {
            $courant = $p['fichier'];
            echo $c('1;36', $courant) . "\n";
        }
        printf("  %4d  %s  %-9s %s\n",
            $p['ligne'],
            $p['niveau'] === 'error' ? $c('31', 'error') : $c('33', 'warn '),
            $p['regle'],
            $p['message']);
    }

    if ($problemes) {
        echo "\n";
    }

    printf("%s : %d erreur(s), %d avertissement(s) dans %d fichier(s)\n",
        $erreurs ? $c('31', 'ÉCHEC') : $c('32', 'OK'),
        count($erreurs), count($alertes), $touches);

    exit($erreurs ? 1 : 0);
}

/* --- Sortie navigateur : le linter se rend avec ses propres composants ---- */

require ROOT . 'libs/site.php';
$e = 'xo_e';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>xoshui-lint</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/libs/css/xoshui.css">
</head>
<body>
<div class="xo-app">

<?php xo_nav('lint'); ?>

  <div class="xo-statusbar">
    <strong><?= $erreurs ? 'ÉCHEC' : 'OK' ?></strong>
    <span><span class="xo-statusbar__label">fichiers:</span> <?= count($fichiers) ?></span>
    <span><span class="xo-statusbar__label">erreurs:</span>
      <span class="<?= $erreurs ? 'xo-danger' : 'xo-success' ?>"><?= count($erreurs) ?></span></span>
    <span><span class="xo-statusbar__label">avertissements:</span>
      <span class="<?= $alertes ? 'xo-warning' : 'xo-muted' ?>"><?= count($alertes) ?></span></span>
    <span class="xo-spacer"></span>
    <span class="xo-faint">référence : <?= $e(SOURCE) ?></span>
  </div>

  <main class="xo-main">

    <?php if (!$problemes): ?>
    <section class="xo-panel">
      <h2 class="xo-panel__title">Résultat</h2>
      <div class="xo-empty">
        <pre class="xo-empty__art" aria-hidden="true">┌──────────────┐
│  rien à      │
│  signaler    │
└──────────────┘</pre>
        <p class="xo-empty__msg">
          <?= count($fichiers) ?> fichiers analysés, aucun manquement.
        </p>
      </div>
    </section>
    <?php else: ?>

    <?php if ($erreurs): ?>
    <div class="xo-alert xo-alert--danger" role="alert" style="margin-bottom: 16px">
      <span aria-hidden="true">✗</span>
      <span class="xo-alert__body">
        <span class="xo-alert__title"><?= count($erreurs) ?> erreur(s).</span>
        Ces manquements cassent la cohérence du framework.
      </span>
    </div>
    <?php endif; ?>

    <section class="xo-panel">
      <h2 class="xo-panel__title">Manquements</h2>
      <div class="xo-log">
        <?php foreach ($problemes as $p): ?>
        <div class="xo-log__line xo-log__line--<?= $p['niveau'] === 'error' ? 'error' : 'warn' ?>">
          <span class="xo-log__time"><?= $e($p['fichier']) ?>:<?= $e($p['ligne']) ?></span>
          <span class="xo-log__level"><?= $e($p['niveau']) ?></span>
          <span class="xo-log__msg">
            <span class="xo-tag"><?= $e($p['regle']) ?></span> <?= $e($p['message']) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <span class="xo-panel__count"><?= count($problemes) ?> lignes</span>
    </section>
    <?php endif; ?>

    <section class="xo-panel xo-panel--pad" style="margin-top: 16px">
      <h2 class="xo-panel__title">Règles</h2>
      <dl class="xo-kv">
        <?php foreach ([
            'hex'       => 'aucune couleur en dur hors de la feuille de référence',
            'radius'    => 'border-radius toujours nul',
            'ombre'     => 'aucune box-shadow ni text-shadow',
            'degrade'   => 'aucun dégradé (repeating-* toléré : arêtes franches)',
            'externe'   => 'aucune ressource chargée depuis le réseau',
            'token'     => 'tout --xo-* utilisé sans repli doit être déclaré',
            'classe'    => 'toute classe xo-* doit exister dans la feuille',
            'hook'      => 'tout data-xo-* doit être monté par xoshui.js',
            'important' => 'avertissement : !important reste une exception',
            'police'    => 'avertissement : font-family en dur',
        ] as $regle => $desc): ?>
        <div class="xo-kv__row">
          <dt><?= $e($regle) ?></dt>
          <span class="xo-kv__leader" aria-hidden="true"></span>
          <dd class="xo-muted"><?= $e($desc) ?></dd>
        </div>
        <?php endforeach; ?>
      </dl>
      <p class="xo-muted" style="margin-top: 8px">
        Une ligne portant <code>xo-lint-ignore</code> est ignorée.
      </p>
    </section>

  </main>

  <div class="xo-keys">
    <span><kbd>php tools/lint.php</kbd> en console</span>
    <span><kbd>--quiet</kbd> manquements seuls</span>
    <span class="xo-spacer"></span>
    <span class="xo-faint">sortie 1 si erreur</span>
  </div>

</div>
<script type="module" src="/libs/js/xoshui.js"></script>
</body>
</html>
