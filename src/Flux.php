<?php
declare(strict_types=1);

/**
 * Le parseur de flux.
 *
 * Les trois formats du web d'actualité, sous une seule sortie :
 * RSS 2.0 (`channel > item`), Atom (`feed > entry`), RDF/RSS 1.0 (`item` à la
 * racine). SimpleXML plutôt que DOM : un flux tient en mémoire, et les espaces
 * de noms se lisent avec `children()`.
 *
 * Le parseur ne juge rien — il extrait. Le score d'alerte et le regroupement
 * viennent après, sur du texte déjà propre.
 */
final class Flux
{
    /**
     * @return list<array{titre: string, lien: string, resume: string, publie: ?int, guid: string}>
     */
    public static function analyser(string $xml, string $urlSource): array
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\n\r");
        if ($xml === '') {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        // LIBXML_NOCDATA replie les CDATA dans le texte : sans lui, un titre
        // entre <![CDATA[…]]> ressort vide.
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return [];
        }

        if (isset($doc->channel->item)) {
            return self::rss($doc->channel->item, $urlSource);
        }
        if (isset($doc->entry)) {
            return self::atom($doc->entry, $urlSource);
        }
        if (isset($doc->item)) {
            return self::rss($doc->item, $urlSource);   // RDF / RSS 1.0
        }

        return [];
    }

    /** @return list<array{titre: string, lien: string, resume: string, publie: ?int, guid: string}> */
    private static function rss(SimpleXMLElement $items, string $urlSource): array
    {
        $sortie = [];

        foreach ($items as $item) {
            $titre = Util::texte((string) $item->title);
            if ($titre === '') {
                continue;
            }

            /* Les agrégateurs suffixent le titre du nom de la rédaction :
               « … des touristes évacués - Le Monde.fr ». Le suffixe fausse le
               rapprochement (il ajoute « monde » aux mots significatifs) et
               empêche deux reprises du même fait de se rejoindre. RSS donne le
               moyen de le retirer proprement : l'élément <source> porte
               exactement le nom ajouté — on ne coupe donc rien à l'aveugle. */
            $redaction = trim((string) $item->source);
            if ($redaction !== '' && str_ends_with($titre, ' - ' . $redaction)) {
                $titre = rtrim(substr($titre, 0, -strlen($redaction) - 3));
            }

            $lien = trim((string) $item->link);
            if ($lien === '') {
                // RDF met le lien dans rdf:about ; certains flux n'ont qu'un guid.
                $about = $item->attributes('rdf', true)['about'] ?? null;
                $lien = trim((string) ($about ?? $item->guid));
            }
            $lien = Util::absolu($lien, $urlSource);
            if (!preg_match('#^https?://#i', $lien)) {
                continue;
            }

            $dc = $item->children('http://purl.org/dc/elements/1.1/');
            $contenu = $item->children('http://purl.org/rss/1.0/modules/content/');

            $resume = (string) $item->description;
            if ($resume === '' && isset($contenu->encoded)) {
                $resume = (string) $contenu->encoded;
            }

            $date = (string) $item->pubDate;
            if ($date === '' && isset($dc->date)) {
                $date = (string) $dc->date;
            }

            $sortie[] = [
                'titre'  => $titre,
                'lien'   => $lien,
                'resume' => Util::tronquer(Util::texte($resume), 400),
                'publie' => Util::horodatage($date),
                'guid'   => trim((string) $item->guid) ?: $lien,
            ];
        }

        return $sortie;
    }

    /** @return list<array{titre: string, lien: string, resume: string, publie: ?int, guid: string}> */
    private static function atom(SimpleXMLElement $entrees, string $urlSource): array
    {
        $sortie = [];

        foreach ($entrees as $entree) {
            $titre = Util::texte((string) $entree->title);
            if ($titre === '') {
                continue;
            }

            // Atom porte le lien dans un attribut, et il peut y en avoir
            // plusieurs : alternate est celui de l'article, pas du flux.
            $lien = '';
            $secours = '';
            foreach ($entree->link as $l) {
                $rel  = (string) ($l['rel'] ?? 'alternate');
                $href = trim((string) ($l['href'] ?? ''));
                if ($href === '') {
                    continue;
                }
                if ($rel === 'alternate') {
                    $lien = $href;
                    break;
                }
                if ($secours === '') {
                    $secours = $href;
                }
            }
            $lien = Util::absolu($lien !== '' ? $lien : $secours, $urlSource);
            if (!preg_match('#^https?://#i', $lien)) {
                continue;
            }

            $resume = (string) $entree->summary;
            if ($resume === '') {
                $resume = (string) $entree->content;
            }

            $date = (string) $entree->published;
            if ($date === '') {
                $date = (string) $entree->updated;
            }

            $sortie[] = [
                'titre'  => $titre,
                'lien'   => $lien,
                'resume' => Util::tronquer(Util::texte($resume), 400),
                'publie' => Util::horodatage($date),
                'guid'   => trim((string) $entree->id) ?: $lien,
            ];
        }

        return $sortie;
    }
}
