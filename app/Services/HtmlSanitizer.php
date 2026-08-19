<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizzazione dell'HTML prodotto dall'editor delle notizie.
 *
 * Il testo passa dal sanitizzatore in scrittura, non in lettura: a database
 * finisce solo HTML gia sicuro, e le pagine pubbliche non pagano il costo della
 * ripulitura a ogni visualizzazione.
 *
 * La lista dei tag ammessi e volutamente corta. Un redattore di notizie ha
 * bisogno di grassetto, corsivo, elenchi, titoli, link e citazioni: tutto il
 * resto (script, iframe, form, attributi di evento, style inline) e superficie
 * d'attacco senza contropartita.
 */
final class HtmlSanitizer
{
    private ?SymfonySanitizer $sanitizer = null;

    /** Ripulisce l'HTML dei contenuti editoriali. */
    public function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $clean = trim($this->sanitizer()->sanitize($html));

        // Un contenuto ridotto a un paragrafo vuoto equivale a nessun contenuto.
        return preg_replace('#^(<p>(\s|&nbsp;)*</p>)+$#u', '', $clean) === '' ? null : $clean;
    }

    /** Rimuove ogni tag: per campi che devono restare testo semplice. */
    public function stripTags(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(strip_tags($text));

        return $text === '' ? null : $text;
    }

    private function sanitizer(): SymfonySanitizer
    {
        if ($this->sanitizer instanceof SymfonySanitizer) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('hr')
            ->allowElement('a', ['href', 'title'])
            // Il testo alternativo e ammesso proprio perche l'accessibilita
            // non deve dipendere da cio che il sanitizzatore lascia passare.
            ->allowElement('figure')
            ->allowElement('figcaption')
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['scope'])
            ->allowElement('td')
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'http', 'data'])
            // I link esterni si aprono in una nuova scheda: senza noopener
            // la pagina di destinazione potrebbe manipolare quella di partenza.
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(500_000);

        return $this->sanitizer = new SymfonySanitizer($config);
    }
}
