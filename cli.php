<?php
declare(strict_types=1);

/**
 * Le démon de collecte et les commandes d'exploitation.
 *
 * Même grammaire que le mode CLI de XOSHUI : un flux de lignes, aucun cadre,
 * aucune sélection. Ce qui se lit de haut en bas et qu'on ne pilote pas.
 *
 *   php cli.php --veille        la boucle de fond — le mode normal
 *   php cli.php --une-fois      un cycle, puis rendre la main
 *   php cli.php --verifier      contrôler les flux et signaler ceux qui ont bougé
 *   php cli.php --fil 30        les trente dernières dépêches
 *   php cli.php --alertes       les événements en alerte
 *   php cli.php --sources       l'état du parc
 *   php cli.php --etat          les compteurs
 *   php cli.php --rescorer      réévaluer le fil après retouche du lexique
 *   php cli.php --enrichir-ia   le second avis du modèle local
 *   php cli.php --purger        effacer ce qui dépasse la rétention
 *
 * Porté depuis Ekein-Scrapper. Un seul écart de fond, et c'est la règle 7 :
 * là-bas chaque cycle s'écrivait dans un journal en fichier, à part de celui de
 * l'écran. Ici il n'y a qu'une chronologie, en base, et elle s'alimente par
 * `Ecran::journaliserCycle()` — la même porte que le cycle déclenché par le
 * web, avec seulement la porte d'entrée qui change. Deux journaux rendraient le
 * méta-agent aveugle à la moitié de ce qu'il fait.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli.php ne s'appelle qu'en ligne de commande.\n");
}

require __DIR__ . '/bootstrap.php';

/* ---- Sortie ------------------------------------------------------------- */

$couleur = !in_array('--sans-couleur', $argv, true)
    && (getenv('TERM') !== false || getenv('WT_SESSION') !== false || getenv('ANSICON') !== false
        || (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true)));

/** Teinte ANSI, ou rien si le terminal ne suit pas. Mêmes rôles que les tokens XOSHUI. */
function teinte(string $texte, string $ton): string
{
    global $couleur;
    if (!$couleur) {
        return $texte;
    }
    $codes = [
        'accent' => '36', 'muted' => '90', 'faint' => '90', 'bold' => '1',
        'success' => '32', 'warning' => '33', 'danger' => '31', 'info' => '36',
    ];

    return isset($codes[$ton]) ? "\033[{$codes[$ton]}m{$texte}\033[0m" : $texte;
}

function ligne(string $texte = ''): void
{
    echo $texte, PHP_EOL;
}

/**
 * Remplissage sur la chasse, pas sur l'octet.
 *
 * str_pad compte des octets : « Libération » en fait onze pour dix caractères,
 * et la colonne suivante se décale d'un cran. Dans une sortie alignée, chaque
 * accent déforme la grille.
 */
function pad(string $texte, int $largeur, bool $gauche = false): string
{
    $blanc = str_repeat(' ', max(0, $largeur - mb_strlen($texte)));

    return $gauche ? $blanc . $texte : $texte . $blanc;
}

/** Le titre de section du mode CLI : une ligne « ==> Titre », pas un cadre. */
function titre(string $texte): void
{
    ligne();
    ligne(teinte('==> ', 'accent') . teinte($texte, 'bold'));
}

function horodate(string $texte): string
{
    return teinte(date('H:i:s'), 'faint') . ' ' . $texte;
}

/** @param array<string, mixed> $r */
function rapport(array $r): string
{
    $nouveaux = (int) $r['nouveaux'];
    $alertes = (int) $r['alertes'];

    return sprintf(
        '%s sources · %s reçues · %s %s%s · %s%s',
        teinte(str_pad((string) $r['sources'], 2, ' ', STR_PAD_LEFT), 'muted'),
        teinte(str_pad((string) $r['recus'], 4, ' ', STR_PAD_LEFT), 'muted'),
        teinte(str_pad((string) $nouveaux, 3, ' ', STR_PAD_LEFT), $nouveaux > 0 ? 'success' : 'faint'),
        teinte('nouvelles', $nouveaux > 0 ? 'success' : 'faint'),
        $alertes > 0 ? teinte(" · $alertes alerte" . ($alertes > 1 ? 's' : ''), 'warning') : '',
        teinte(Util::duree((int) $r['ms']), 'faint'),
        (int) $r['erreurs'] > 0 ? teinte(' · ' . (int) $r['erreurs'] . ' en défaut', 'danger') : ''
    );
}

/* ---- Arguments ---------------------------------------------------------- */

$args = array_slice($argv, 1);
$commande = '--aide';
foreach ($args as $a) {
    if (str_starts_with($a, '--') && $a !== '--sans-couleur' && $a !== '--tout') {
        $commande = $a;
        break;
    }
}
$nombre = 0;
foreach ($args as $a) {
    if (ctype_digit($a)) {
        $nombre = (int) $a;
        break;
    }
}

$base = new Base((string) narh_reglage('base_veille'));
$base->synchroniser(require NARH_RACINE . '/config/sources.php');
$collecteur = new Collecteur($base);

/* ---- Commandes ---------------------------------------------------------- */

switch ($commande) {

    /* -- Un cycle ------------------------------------------------------- */
    case '--une-fois':
    case '--once':
        $r = $collecteur->cycle(in_array('--tout', $args, true));
        Ecran::journaliserCycle($r, 'à la main');
        ligne(horodate(rapport($r)));
        break;

    /* -- La boucle de fond ---------------------------------------------- */
    case '--veille':
    case '--watch':
        ligne(teinte('NARH ' . NARH_VERSION, 'bold') . teinte(' — veille en cours. Ctrl+C pour arrêter.', 'muted'));
        ligne(teinte('Collecte : ' . narh_reglage('base_veille'), 'faint'));
        ligne(teinte('Journal  : ' . narh_reglage('base'), 'faint'));
        ligne();

        Journal::noter('ok', 'collecte', 'démon démarré');

        $purge = 0;
        while (true) {
            $r = $collecteur->cycle();

            /* Le journal ne voit passer que ce qui a eu lieu : un cycle sauté
               est le verrou qui fait son travail, pas un incident.
               `journaliserCycle()` le sait déjà — on ne redécide pas ici. */
            Ecran::journaliserCycle($r, 'démon');

            if ($r['saute']) {
                ligne(horodate(teinte('cycle déjà en cours ailleurs — passe', 'muted')));
            } elseif ($r['sources'] > 0) {
                ligne(horodate(rapport($r)));
            }

            // Une purge par heure suffit : elle n'est pas le travail du cycle.
            if (time() - $purge > 3600) {
                $purge = time();
                $efface = $base->purger(time() - (int) narh_reglage('retention', 4) * 86400);
                if ($efface > 0) {
                    Journal::noter('info', 'collecte', "purge : $efface dépêches retirées");
                    ligne(horodate(teinte("purge : $efface dépêches retirées", 'muted')));
                }
            }

            // On dort jusqu'à la prochaine source échue, jamais moins de 5 s.
            $dues = $base->sourcesDues(time() + 300);
            sleep(max(5, min(60, $dues === [] ? 30 : 10)));
        }
        // no break — la boucle ne rend jamais la main

    /* -- Contrôle du parc ------------------------------------------------ */
    case '--verifier':
        titre('Contrôle des flux');
        $sources = $base->sources();
        $requetes = [];
        foreach ($sources as $s) {
            if ((int) $s['actif'] === 1) {
                $requetes[(string) $s['id']] = ['url' => (string) $s['url']];
            }
        }

        $debut = microtime(true);
        $reponses = (new Http(narh_reglages()))->lot($requetes);
        $ok = $ko = 0;

        foreach ($sources as $s) {
            $id = (string) $s['id'];
            if (!isset($reponses[$id])) {
                continue;
            }
            $r = $reponses[$id];
            $items = $r['corps'] !== '' ? Flux::analyser((string) $r['corps'], (string) $s['url']) : [];
            $sain = $r['code'] >= 200 && $r['code'] < 300 && $items !== [];
            $sain ? $ok++ : $ko++;

            printf(
                "%s %s %s %s %s  %s\n",
                $sain ? teinte('*', 'success') : teinte('!', 'danger'),
                pad($id, 15),
                str_pad((string) $r['code'], 3, ' ', STR_PAD_LEFT),
                str_pad(Util::duree((int) $r['ms']), 7, ' ', STR_PAD_LEFT),
                str_pad(count($items) . ' art.', 9, ' ', STR_PAD_LEFT),
                teinte($items !== [] ? Util::tronquer($items[0]['titre'], 58) : ($r['erreur'] ?? 'aucun article lisible'), $sain ? 'muted' : 'danger')
            );
        }

        ligne();
        ligne(sprintf(
            '%s sains, %s en défaut, en %s',
            teinte((string) $ok, 'success'),
            teinte((string) $ko, $ko > 0 ? 'danger' : 'muted'),
            Util::duree((int) round((microtime(true) - $debut) * 1000))
        ));
        Journal::noter($ko > 0 ? 'warn' : 'ok', 'collecte', "contrôle des flux : $ok sains, $ko en défaut");
        exit($ko > 0 ? 1 : 0);

    /* -- Le fil ---------------------------------------------------------- */
    case '--fil':
        $articles = $base->flux(['limite' => $nombre > 0 ? $nombre : 30]);
        titre('Fil — ' . count($articles) . ' dernières dépêches');
        foreach (array_reverse($articles) as $a) {
            $niveau = (int) $a['niveau'];
            $marque = [teinte('-', 'faint'), teinte('-', 'info'), teinte('*', 'warning'), teinte('*', 'danger')][$niveau];
            printf(
                "%s %s %s %s %s\n",
                $marque,
                teinte(date('H:i', (int) $a['date_tri']), 'faint'),
                teinte(pad(Util::tronquer((string) $a['source_nom'], 13), 13), 'muted'),
                $niveau >= 2 ? teinte((string) $a['titre'], 'bold') : (string) $a['titre'],
                (int) ($a['reprises'] ?? 1) > 1 ? teinte('×' . (int) $a['reprises'], 'accent') : ''
            );
        }
        break;

    /* -- Les alertes ----------------------------------------------------- */
    case '--alertes':
        $alertes = $base->alertes(time() - 21600, Alerte::ALERTE, $nombre > 0 ? $nombre : 20);
        titre('Alertes — 6 dernières heures');
        if ($alertes === []) {
            ligne(teinte('  aucune.', 'muted'));
            break;
        }
        foreach ($alertes as $g) {
            printf(
                "%s %s %s %s\n",
                teinte((int) $g['niveau'] >= 3 ? '!' : '*', (int) $g['niveau'] >= 3 ? 'danger' : 'warning'),
                teinte(date('H:i', (int) $g['dernier']), 'faint'),
                teinte(pad('×' . (int) $g['sources'], 4), 'accent'),
                teinte(Util::tronquer((string) $g['titre'], 88), 'bold')
            );
            if (trim((string) $g['motifs']) !== '') {
                ligne('       ' . teinte((string) $g['motifs'] . ' · score ' . (int) $g['score'], 'muted'));
            }
        }
        break;

    /* -- Le parc --------------------------------------------------------- */
    case '--sources':
        titre('Sources');
        $rubrique = '';
        foreach ($base->sources() as $s) {
            if ((string) $s['rubrique'] !== $rubrique) {
                $rubrique = (string) $s['rubrique'];
                ligne();
                ligne(teinte('  ' . mb_strtoupper($rubrique), 'accent'));
            }
            $echecs = (int) $s['echecs'];
            $marque = match (true) {
                (int) $s['actif'] !== 1 => teinte('-', 'faint'),
                $echecs >= (int) narh_reglage('echecs_morte', 6) => teinte('!', 'danger'),
                $echecs > 0 => teinte('!', 'warning'),
                default => teinte('*', 'success'),
            };
            printf(
                "  %s %s %s %s %s\n",
                $marque,
                pad((string) $s['id'], 15),
                teinte(str_pad((string) $s['cadence'] . 's', 5, ' ', STR_PAD_LEFT), 'faint'),
                teinte(str_pad((int) $s['total'] . ' art.', 10, ' ', STR_PAD_LEFT), 'muted'),
                teinte((int) $s['succes'] > 0 ? Util::age((int) $s['succes']) : 'jamais relevée', $echecs > 0 ? 'warning' : 'faint')
            );
        }
        break;

    /* -- Les compteurs --------------------------------------------------- */
    case '--etat':
        $stats = $base->stats(time());
        $cycle = $base->cycle();
        titre('État');
        foreach ([
            'Collecte'        => (string) narh_reglage('base_veille'),
            'Mémoire'         => (string) narh_reglage('base'),
            'Dépêches'        => (string) $stats['articles'],
            'Dernière heure'  => (string) $stats['h1'],
            'Dernier jour'    => (string) $stats['h24'],
            'Événements'      => (string) $stats['groupes'],
            'Niveaux (6 h)'   => sprintf(
                'info %d · veille %d · alerte %d · urgent %d',
                $stats['niveaux'][0], $stats['niveaux'][1], $stats['niveaux'][2], $stats['niveaux'][3]
            ),
            'Sources saines'  => ($stats['sources']['saines'] ?? 0) . '/' . ($stats['sources']['total'] ?? 0),
            'Sources mortes'  => (string) ($stats['sources']['mortes'] ?? 0),
            'Dernier cycle'   => ($cycle['fin'] ?? 0) > 0
                ? Util::age((int) $cycle['fin']) . ' · ' . Util::duree((int) ($cycle['ms'] ?? 0))
                    . ' · ' . (int) ($cycle['nouveaux'] ?? 0) . ' nouvelles'
                : 'jamais',
            'Journal'         => Journal::compter(time() - 86400) . ' entrées sur 24 h',
        ] as $cle => $valeur) {
            printf("  %s %s %s\n", teinte(pad($cle, 16), 'muted'), teinte('·', 'faint'), $valeur);
        }
        break;

    /* -- Réévaluation ----------------------------------------------------
       À passer après toute retouche du lexique ou des seuils : les scores en
       base ont été calculés avec les anciens, et rien ne les recalcule tout
       seul — une dépêche n'est évaluée qu'à son arrivée. */
    case '--rescorer':
        titre('Réévaluation du fil');
        $pdo = $base->pdo();
        $articles = $pdo->query('SELECT id, titre, resume FROM article')->fetchAll();

        $maj = $pdo->prepare(
            'UPDATE article SET lexique = :lexique, score = :lexique, motifs = :motifs WHERE id = :id'
        );
        $pdo->beginTransaction();
        foreach ($articles as $a) {
            $note = Alerte::lexique((string) $a['titre'], (string) $a['resume']);
            $maj->execute([
                'lexique' => $note['score'],
                'motifs'  => implode(', ', $note['motifs']),
                'id'      => (int) $a['id'],
            ]);
        }
        $pdo->commit();

        $groupes = $pdo->query('SELECT id FROM groupe')->fetchAll();
        $maintenant = time();
        $alertes = 0;
        foreach ($groupes as $g) {
            if ($base->recalculerGroupe((int) $g['id'], $maintenant)['niveau'] >= Alerte::ALERTE) {
                $alertes++;
            }
        }

        ligne(sprintf(
            '  %s dépêches réévaluées, %s événements recalculés, %s en alerte.',
            teinte((string) count($articles), 'bold'),
            teinte((string) count($groupes), 'bold'),
            teinte((string) $alertes, $alertes > 0 ? 'warning' : 'muted')
        ));
        Journal::noter('ok', 'collecte', sprintf(
            'réévaluation : %d dépêches, %d événements, %d en alerte',
            count($articles),
            count($groupes),
            $alertes
        ));
        break;

    /* -- Second avis -----------------------------------------------------
       Le seul endroit d'où part un appel au modèle au sujet de la collecte.
       Hors du cycle : Ollama répond en secondes et n'est pas toujours lancé,
       le mêler au relevé mettrait le budget du cycle à la merci d'un service
       éteint. Et ne se planifie pas — voir CLAUDE.md, règles du projet. */
    case '--enrichir-ia':
        titre('Second avis');
        if (!narh_reglage('ia_activee', false)) {
            ligne(teinte('  ia_activee est à false — rien à faire.', 'muted'));
            ligne(teinte('  L\'activer dans config/reglages.local.php.', 'faint'));
            exit(0);
        }

        $ia = Ia::depuisReglages();
        $maintenant = time();
        $candidats = $base->groupesACandidater(
            (int) narh_reglage('ia_marge', 2),
            (int) narh_reglage('ia_lot', 10)
        );

        $juges = $ecarts = $muets = 0;
        foreach ($candidats as $g) {
            $niveau = (int) $g['niveau'];
            $avis = $ia->jugerNiveau(
                (string) $g['titre'],
                (string) ($g['resume'] ?? ''),
                (int) $g['sources']
            );

            if ($avis === null) {
                $muets++;
                // Horodater quand même : un événement que le modèle n'a pas su
                // juger ne doit pas repartir en tête du lot au passage suivant.
                $base->enregistrerAvisIa((int) $g['id'], null, '', $maintenant);
                printf("%s %s\n", teinte('?', 'faint'), teinte(Util::tronquer((string) $g['titre'], 76), 'faint'));
                continue;
            }

            $juges++;
            $ecart = $avis['niveau'] !== $niveau;
            if ($ecart) {
                $ecarts++;
            }
            /* L'avis se range à côté du score, jamais dedans (règle 4) :
               `enregistrerAvisIa` écrit dans ses propres colonnes et ne touche
               ni à `article.niveau` ni à `groupe.score`. */
            $base->enregistrerAvisIa((int) $g['id'], $avis['niveau'], $avis['motif'], $maintenant);

            printf(
                "%s %s %s %s\n",
                teinte($ecart ? '~' : '*', $ecart ? 'warning' : 'success'),
                teinte(pad(Alerte::nom($niveau) . ' → ' . Alerte::nom($avis['niveau']), 20), $ecart ? 'accent' : 'faint'),
                Util::tronquer((string) $g['titre'], 60),
                teinte($avis['motif'], 'muted')
            );
        }

        ligne();
        ligne(sprintf(
            '  %s événements jugés, %s en écart avec le barème, %s sans réponse.',
            teinte((string) $juges, 'bold'),
            teinte((string) $ecarts, $ecarts > 0 ? 'warning' : 'muted'),
            teinte((string) $muets, $muets > 0 ? 'danger' : 'muted')
        ));
        Journal::noter(
            $muets > 0 ? 'warn' : 'ok',
            'second avis',
            "$juges événements jugés, $ecarts en écart, $muets sans réponse"
        );

        /* -- Le pic ------------------------------------------------------- */
        $pas = (int) narh_reglage('debit_pas', 10);
        $fenetre = (int) narh_reglage('debit_fenetre', 180);
        $serie = $base->debit($maintenant, $fenetre, $pas);

        titre('Débit');
        if (!Base::picDetecte($serie, (float) narh_reglage('pic_facteur', 2.5), (int) narh_reglage('pic_min', 8))) {
            ligne(teinte('  Rythme ordinaire, rien à commenter.', 'muted'));
            break;
        }

        // Un pic commenté le reste : sans cette garde, un passage toutes les
        // cinq minutes redemanderait le même commentaire pendant trois heures.
        if ($base->commentairePic($maintenant, $fenetre * 60) !== null) {
            ligne(teinte('  Pic déjà commenté.', 'muted'));
            break;
        }

        $titres = $base->titresRecents($maintenant - 2 * $pas * 60);
        $texte = $ia->commenterPic($titres);
        if ($texte === null) {
            ligne(teinte('  Pic détecté, mais le modèle n\'a rien rendu.', 'danger'));
            Journal::noter('warn', 'second avis', 'pic détecté, modèle muet');
            exit(1);
        }

        $base->setMeta('pic', (string) json_encode(
            ['texte' => $texte, 'quand' => $maintenant],
            JSON_UNESCAPED_UNICODE
        ));
        ligne('  ' . teinte('▲', 'warning') . ' ' . $texte);
        Journal::noter('info', 'second avis', 'pic commenté : ' . $texte);
        break;

    /* -- Entretien ------------------------------------------------------- */
    case '--purger':
        $avant = time() - (int) narh_reglage('retention', 4) * 86400;
        $n = $base->purger($avant);
        $base->compacter();
        ligne(horodate("$n dépêches retirées, base compactée."));
        Journal::noter('ok', 'collecte', "purge à la main : $n dépêches retirées");
        break;

    /* -- Aide ------------------------------------------------------------ */
    default:
        ligne(teinte('NARH ' . NARH_VERSION, 'bold') . teinte(' — méta-agent : la veille et la voix', 'muted'));
        ligne();
        ligne(teinte('==> ', 'accent') . teinte('Commandes', 'bold'));
        foreach ([
            '--veille'      => 'la boucle de fond — laisser tourner, l\'écran devient un lecteur',
            '--une-fois'    => 'un cycle de collecte, puis rendre la main (--tout : toutes les sources)',
            '--verifier'    => 'contrôler chaque flux et signaler ceux qui ont changé d\'adresse',
            '--fil [n]'     => 'les n dernières dépêches (30 par défaut)',
            '--alertes'     => 'les événements repris par plusieurs rédactions',
            '--sources'     => 'l\'état du parc, rubrique par rubrique',
            '--etat'        => 'les compteurs',
            '--rescorer'    => 'réévaluer tout le fil après une retouche du lexique ou des seuils',
            '--enrichir-ia' => 'demander au modèle son avis sur les scores limites et les pics',
            '--purger'      => 'effacer au-delà de la rétention et compacter la base',
        ] as $cle => $role) {
            printf("  %s %s %s\n", teinte('»', 'accent'), teinte(pad($cle, 14), 'bold'), teinte($role, 'muted'));
        }
        ligne();
        ligne(teinte('  Avec --veille en fond, mettre collecte_web à false dans', 'faint'));
        ligne(teinte('  config/reglages.local.php : l\'écran devient un lecteur de la base.', 'faint'));
        ligne();
        ligne(teinte('  L\'écran : http://narh-agent.test', 'faint'));
        ligne();
        break;
}
