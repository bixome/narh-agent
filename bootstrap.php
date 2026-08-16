<?php
declare(strict_types=1);

/**
 * NARH — amorçage.
 *
 * Le seul fichier que tout le reste inclut : constantes de chemin, autoload,
 * réglages, échappement de sortie. Aucune dépendance, aucun composer.
 *
 * Interface : XOSHUI en mode console (libs/css/xoshui.css, classe xo-console).
 */

if (PHP_VERSION_ID < 80200) {
    exit('NARH demande PHP 8.2 ou plus (ici ' . PHP_VERSION . ").\n");
}

const NARH_RACINE  = __DIR__;
const NARH_VAR     = __DIR__ . '/var';
const NARH_VERSION = '0.1';

/* La phase en cours. Elle ne pilote aucun comportement : elle s'affiche, pour
   qu'un écran encore muet dise pourquoi il l'est plutôt que de passer pour
   cassé. À retirer quand la v1 est complète. */
const NARH_PHASE = 'P0';

/* Sans ce réglage explicite, PHP retombe sur l'UTC de l'ini (jamais fixé sur
   cette machine) : tout l'horodatage — journal, heure donnée au modèle, dates
   lues dans la veille — dériverait alors de deux heures par rapport à ce que
   l'écran affiche. Un seul point de vérité ici. */
date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $classe): void {
    if (!preg_match('/^[A-Z][A-Za-z]+$/', $classe)) {
        return;
    }
    $fichier = NARH_RACINE . '/src/' . $classe . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

/** @return array<string, mixed> */
function narh_reglages(): array
{
    static $reglages = null;

    if ($reglages === null) {
        $reglages = require NARH_RACINE . '/config/reglages.php';
        // Réglages de poste : jamais versionnés, ils écrasent les précédents.
        $local = NARH_RACINE . '/config/reglages.local.php';
        if (is_file($local)) {
            $reglages = array_replace($reglages, require $local);
        }
    }

    return $reglages;
}

function narh_reglage(string $cle, mixed $defaut = null): mixed
{
    return narh_reglages()[$cle] ?? $defaut;
}

/** Échappement de sortie — à mettre autour de toute valeur écrite dans le HTML. */
function e(string|int|float|null $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!is_dir(NARH_VAR) && !mkdir(NARH_VAR, 0o775, true) && !is_dir(NARH_VAR)) {
    exit('Impossible de créer ' . NARH_VAR . "\n");
}
