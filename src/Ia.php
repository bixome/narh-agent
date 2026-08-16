<?php
declare(strict_types=1);

/**
 * Le second avis du modèle sur la collecte.
 *
 * Consultatif, jamais décisionnaire (CLAUDE.md, règle 4) : `article.niveau` et
 * `groupe.score` restent la sortie du lexique et de la reprise, seules
 * reproductibles. Ce que le modèle dit s'affiche **à côté**, tagué comme tel.
 *
 * Rien ici n'appartient au cycle de collecte. Un modèle local répond en
 * secondes quand un flux répond en millisecondes, et il n'est pas toujours
 * lancé : le mêler au relevé mettrait le budget du cycle à la merci d'un
 * service éteint. D'où `php cli.php --enrichir-ia`, à part, à la main.
 *
 * Porté depuis Ekein-Scrapper, avec un écart : là-bas cette classe ouvrait son
 * propre cURL vers Ollama, faute de client dans le projet. NARH en a un —
 * `src/Ollama.php` — et deux clients pour un même moteur auraient divergé au
 * premier réglage ajouté d'un côté seulement.
 *
 * Toute méthode rend null plutôt que de lever : Ollama absent, trop lent ou
 * bavard hors format ne doit jamais interrompre le passage.
 */
final class Ia
{
    public function __construct(
        private readonly Ollama $moteur,
        private readonly string $modele,
        private readonly int $timeout,
    ) {
    }

    public static function depuisReglages(): self
    {
        /** @var array<string, mixed> $ollama */
        $ollama = (array) narh_reglage('ollama', []);

        return new self(
            new Ollama((string) ($ollama['url'] ?? 'http://127.0.0.1:11434')),
            (string) ($ollama['modele'] ?? 'llama3.2:3b'),
            (int) narh_reglage('ia_timeout', 20),
        );
    }

    /**
     * L'avis sur un événement : un niveau, et la raison en quelques mots.
     *
     * @return array{niveau: int, motif: string}|null
     */
    public function jugerNiveau(string $titre, string $resume, int $reprises): ?array
    {
        $invite = <<<TXT
            Tu notes l'urgence d'une dépêche d'actualité française pour une
            veille de rédaction. Barème :
            0 information ordinaire ; 1 à surveiller ; 2 fait marquant,
            à signaler ; 3 événement majeur, qui interrompt une conférence
            de rédaction.

            Titre : {$titre}
            Résumé : {$resume}
            Reprise : {$reprises} rédaction(s) titrent le même événement.

            Réponds par un objet JSON, et rien d'autre :
            {"niveau": 0, "motif": "six mots au plus, en français"}
            TXT;

        $brut = $this->generer($invite, true, 80);
        if ($brut === null) {
            return null;
        }

        $avis = json_decode($brut, true);
        if (!is_array($avis) || !isset($avis['niveau']) || !is_numeric($avis['niveau'])) {
            return null;
        }

        return [
            'niveau' => max(Alerte::INFO, min(Alerte::URGENT, (int) $avis['niveau'])),
            'motif'  => Util::tronquer(trim((string) ($avis['motif'] ?? '')), 60),
        ];
    }

    /**
     * Ce qui explique une accélération des arrivées, en une ligne.
     *
     * @param list<string> $titres
     */
    public function commenterPic(array $titres): ?string
    {
        if ($titres === []) {
            return null;
        }

        $liste = implode("\n", array_map(static fn (string $t): string => '- ' . $t, $titres));
        $invite = <<<TXT
            Voici les dépêches arrivées pendant une accélération du fil d'une
            veille d'actualité française :

            {$liste}

            En une seule phrase de vingt mots au plus, en français, dis ce qui
            explique cette accélération. Pas de préambule, pas de liste, pas de
            guillemets — la phrase seule.
            TXT;

        $texte = $this->generer($invite, false, 60);
        if ($texte === null) {
            return null;
        }

        // Un modèle bavard répond parfois sur deux lignes malgré la consigne.
        $texte = trim(strtok(trim($texte), "\n") ?: '');

        return $texte === '' ? null : Util::tronquer($texte, 160);
    }

    /** L'appel lui-même. Null dès que quoi que ce soit cloche. */
    private function generer(string $invite, bool $json, int $maxJetons): ?string
    {
        /* Température basse et non celle des réglages : on demande un jugement
           reproductible, pas une conversation. Le réglage `ollama.temperature`
           appartient à la voix, pas au second avis. */
        $r = $this->moteur->phrase(
            $this->modele,
            [['role' => 'user', 'content' => $invite]],
            0.2,
            $this->timeout,
            $maxJetons,
            $json,
        );

        return $r === null || trim($r['texte']) === '' ? null : trim($r['texte']);
    }
}
