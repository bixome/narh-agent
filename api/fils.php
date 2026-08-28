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
            /* La seule suppression de l'application, et elle n'avait aucune
               garde : `Memoire::filSupprimer()` était appelée avec ce que le
               navigateur envoyait. Le geste vivait dans la même barre que sept
               gestes de veille, si bien qu'une dépêche sélectionnée y expédiait
               son identifiant d'**article**. Que les fils s'arrêtent à quelques
               dizaines et les dépêches commencent à douze mille est un accident
               d'intervalles, pas une protection : la première dépêche portant un
               numéro bas aurait effacé une conversation, en cascade sur ses
               messages, sans rien à l'écran pour le dire. */
            if ($id <= 0 || !Memoire::filExiste($id)) {
                repondre(['ok' => false, 'erreur' => "Aucun fil #$id à oublier."], 404);
            }

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

    /* Les actions au-dessus sont les seules à écrire en session. Ce qui suit
       interroge le moteur et rend la conversation entière — une cinquantaine
       de kilo-octets — et n'a aucune raison de garder le verrou pendant ce
       temps. Mesuré avant : 18,5 s au chargement, le temps que la file
       s'écoule. */
    Agent::filRendreLaMain();

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
    $alertes = $base->alertes($maintenant - 21600, Alerte::ALERTE, 3, true);
    // Lu une fois : il sert aux pastilles des onglets **et** à la ligne « 40
    // sur 70 » de chaque liste. Deux lectures auraient pu se contredire entre
    // le compte de l'onglet et celui de son contenu.
    $statuts = $base->comptesStatuts();
    $etatOutils = Memoire::etatOutils($courant);

    repondre([
        'ok'       => true,
        'fil'      => $courant,
        'fils'     => Vue::fils(Memoire::fils(30, $courant), $courant),
        // Alertes et veille en une seule liste, le grave d'abord — la même que
        // celle du Newsdesk, pour qu'un rafraîchissement ne change pas l'ordre.
        'veille'   => Vue::lignesEvenements(
            Ecran::alertesPuisVeille(
                $alertes,
                // Le même classement qu'au chargement : rouvrir l'onglet ne doit
                // pas réordonner ce qu'on était en train de lire.
                $base->arbre(['classement' => 'consistance', 'description' => true, 'traitement' => true], Ecran::DESK_LIGNES),
            ),
        ),
        'outils'   => Vue::outils(Memoire::outils($courant, 20)),
        /* Le compteur du panneau. Il était lu par le navigateur (`data.compte`)
           mais n'a jamais été rendu ici : `texte()` écrivait donc la chaîne
           « undefined » dans l'écran après chaque réponse. `echecs` l'accompagne
           parce qu'un appel raté ne se voit pas dans un total. */
        'compte'   => $etatOutils['compte'],
        'echecs'   => $etatOutils['echecs'],
        /* Les trois marquages, avec leurs comptes : un geste de desk change ce
           que ces onglets montrent, et l'onglet doit pouvoir se recharger seul.

           Le total accompagne chaque liste, comme au chargement : sans lui, un
           onglet rouvert perdait la ligne « 40 sur 70 » que la page servait, et
           redevenait le compte muet qu'on vient de corriger. */
        // Les trois marquages en une seule liste — l'onglet « Marqués ».
        'marques'  => Vue::lignesEvenements(
            $base->arbre(['marques' => true, 'traitement' => true, 'description' => true], Ecran::DESK_LIGNES),
            (int) ($statuts['suivi'] + $statuts['traite'] + $statuts['ecarte']),
        ),
        /* `marques` accompagne les trois comptes : c'est lui que la pastille de
           l'onglet affiche, et le recomposer côté navigateur aurait remis une
           addition dans le rendu, que la règle 2 garde en PHP. */
        'statuts'  => $statuts + [
            'marques' => (int) ($statuts['suivi'] + $statuts['traite'] + $statuts['ecarte']),
        ],
        /* Les deux moitiés de la même chronologie, rendues séparément parce
           qu'elles se lisent à deux endroits : le dialogue dans la colonne de
           l'agent, le reste au desk. Une seule liste rendue deux fois aurait
           demandé au navigateur de trier, donc de connaître les rôles — c'est
           au serveur de le savoir (règle 2). */
        'tours'    => Vue::tours(
            $courant > 0 ? Memoire::messages($courant) : [],
            Vue::CHRONOLOGIE,
        ),
        'dialogue' => Vue::tours(
            $courant > 0 ? Memoire::messages($courant) : [],
            Vue::DIALOGUE,
        ),
        /* Le moniteur du compte : un tour de plus, des jetons de plus. Rendus
           ici comme tout le reste — le navigateur remplace un bloc déjà écrit,
           il n'en compose pas (règle 2). */
        'compteurs' => Vue::compteurs(Memoire::bilan(), Corpus::etat()),
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
