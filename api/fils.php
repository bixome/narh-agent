<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/**
 * Le panneau de chat, hors flux de jetons.
 *
 * Tout ce qui a une forme arrive rendu par `Vue` (CLAUDE.md, règle 2) : la
 * liste des fils, la conversation, l'état du moteur. Seul le flux de jetons
 * passe par api/chat.php, en texte — un jeton n'a pas de forme.
 *
 *   GET api.php/fils.php?action=etat            — fils + conversation + moteur
 *   GET api/fils.php?action=ouvrir&id=12        — bascule de fil
 *   GET api/fils.php?action=neuf                — fil neuf
 *   GET api/fils.php?action=oublier&id=12       — suppression
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function repondre(array $charge, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'etat';
    $id = (int) ($_GET['id'] ?? 0);

    match ($action) {
        'ouvrir'  => Agent::filOuvrir($id),
        'neuf'    => Agent::filNeuf(),
        'oublier' => (function () use ($id): void {
            Memoire::filSupprimer($id);
            Journal::noter('warn', 'agent', "fil #$id oublié");
            // Supprimer celui qu'on lisait laisse un pointeur mort : on repart
            // sur un fil neuf plutôt que sur une erreur au prochain message.
            if ($id === Agent::filId()) {
                Agent::filNeuf();
            }
        })(),
        default   => null,
    };

    $courant = Agent::filId();
    Memoire::purgerVides($courant);

    /* L'état du moteur vit aussi entre les questions : un modèle se décharge
       tout seul après quelques minutes d'inactivité, et l'écran doit pouvoir le
       dire sans qu'on ait rien demandé. */
    $reglages = Agent::reglages();
    $ollama = new Ollama((string) narh_reglage('ollama')['url']);
    $enLigne = $ollama->disponible();

    $charge = null;
    if ($enLigne) {
        foreach ($ollama->etat()['charges'] as $m) {
            if ($m['nom'] === $reglages['modele']) {
                $charge = $m;
            }
        }
    }

    /* La jauge de contexte a besoin des deux bouts : ce qu'a relu le dernier
       tour (en base) et la fenêtre du modèle **réellement chargé** (chez
       Ollama). Elle est rendue ici, comme tout ce qui a une forme — le
       navigateur n'a qu'à la poser. */
    /* Les alertes de l'en-tête : courtes, et rendues ici parce que l'onglet du
       Newsdesk les redemande sans recharger la page. */
    $base = new Base((string) narh_reglage('base_veille'));
    $maintenant = time();
    $alertes = $base->alertes($maintenant - 21600, Alerte::ALERTE, 3);
    $etatOutils = Memoire::etatOutils($courant);

    repondre([
        'ok'       => true,
        'fil'      => $courant,
        'fils'     => Vue::fils(Memoire::fils(30, $courant), $courant),
        // Alertes et veille en une seule liste, le grave d'abord — la même que
        // celle du Newsdesk, pour qu'un rafraîchissement ne change pas l'ordre.
        'veille'   => Vue::lignesEvenements(Ecran::alertesPuisVeille($alertes, $base->arbre([], 12))),
        'outils'   => Vue::outils(Memoire::outils($courant, 20)),
        /* Le compteur du panneau. Il était lu par le navigateur (`data.compte`)
           mais n'a jamais été rendu ici : `texte()` écrivait donc la chaîne
           « undefined » dans l'écran après chaque réponse. `echecs` l'accompagne
           parce qu'un appel raté ne se voit pas dans un total. */
        'compte'   => $etatOutils['compte'],
        'echecs'   => $etatOutils['echecs'],
        // Les trois marquages, avec leurs comptes : un geste de desk change ce
        // que ces onglets montrent, et l'onglet doit pouvoir se recharger seul.
        'suivis'   => Vue::lignesEvenements($base->arbre(['statut' => 'suivi'], 12)),
        'traites'  => Vue::lignesEvenements($base->arbre(['statut' => 'traite'], 12)),
        'ecartes'  => Vue::lignesEvenements($base->arbre(['statut' => 'ecarte'], 12)),
        'statuts'  => $base->comptesStatuts(),
        'tours'    => Vue::tours($courant > 0 ? Memoire::messages($courant) : []),
        'contexte' => Vue::contexte(
            $courant > 0 ? Memoire::contexteDernier($courant) : 0,
            (int) ($charge['contexte'] ?? 0),
        ),
        'moteur'   => [
            'en_ligne' => $enLigne,
            'modele'   => (string) $reglages['modele'],
            // « chargé » et « installé » sont deux choses : un modèle installé
            // mais déchargé répond, mais paie d'abord son chargement en mémoire.
            'charge'   => $charge !== null,
        ],
    ]);
} catch (Throwable $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], 500);
}
