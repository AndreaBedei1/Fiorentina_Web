<?php

declare(strict_types=1);

namespace App\Core\Support;

use Cocur\Slugify\Slugify;

/** Utilita di manipolazione stringhe usate in tutto il progetto. */
final class Str
{
    private static ?Slugify $slugify = null;

    /**
     * Slug adatto agli URL: minuscolo, senza accenti, separato da trattini.
     *
     * Gli slug sono parte della SEO e vengono salvati a database: la
     * generazione deve essere stabile e deterministica.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        self::$slugify ??= new Slugify([
            'lowercase' => true,
            'trim' => true,
            'regexp' => '/([^A-Za-z0-9]|-)+/',
        ]);

        $slug = self::$slugify->slugify($value, $separator);

        // Uno slug vuoto renderebbe l'URL ambiguo: meglio un fallback esplicito.
        return $slug !== '' ? mb_substr($slug, 0, 190) : 'voce';
    }

    /** Taglia il testo a `$limit` caratteri senza spezzare le parole. */
    public static function truncate(string $value, int $limit = 160, string $ending = '...'): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $cut = mb_substr($value, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($limit * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:!?-") . $ending;
    }

    /** Estratto testuale da un contenuto HTML: usato per meta description e card. */
    public static function excerpt(string $html, int $limit = 200): string
    {
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h2>', '</h3>'], ' ', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::truncate($text, $limit);
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_starts_with($haystack, $needle);
    }

    public static function limitWords(string $value, int $words = 30, string $ending = '...'): string
    {
        $parts = preg_split('/\s+/u', trim($value)) ?: [];

        if (count($parts) <= $words) {
            return implode(' ', $parts);
        }

        return implode(' ', array_slice($parts, 0, $words)) . $ending;
    }

    /** Iniziali per gli avatar segnaposto dell'organigramma. */
    public static function initials(string $name, int $max = 2): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
        $initials = '';

        foreach (array_slice($parts, 0, $max) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Formatta un importo secondo la convenzione italiana: 1.234,50 €.
     *
     * Il simbolo va dopo la cifra e con uno spazio, come si scrive in Italia
     * e come lo scrivono i cartellini dei negozi. Prima qui c'era la parola
     * "euro" per esteso: si legge bene ad alta voce, ma su una card di
     * prodotto occupa il triplo dello spazio di quello che dice.
     */
    public static function money(float|int|string $amount, bool $withSymbol = true): string
    {
        $formatted = number_format((float) $amount, 2, ',', '.');

        return $withSymbol ? $formatted . ' €' : $formatted;
    }

    /** Genera una stringa casuale sicura (identificativi di file, chiavi di cache). */
    public static function random(int $length = 16): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /** Normalizza gli a-capo e rimuove i caratteri di controllo dai testi liberi. */
    public static function cleanText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return trim($value);
    }

    /** Converte testo semplice in HTML preservando i paragrafi. */
    public static function nl2paragraphs(string $text): string
    {
        $blocks = preg_split('/\n{2,}/', self::cleanText($text)) ?: [];
        $html = '';

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $html .= '<p>' . nl2br(htmlspecialchars($block, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        }

        return $html;
    }

    /** Maschera un indirizzo email per i log (privacy nelle registrazioni tecniche). */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return '***';
        }

        [$local, $domain] = $parts;
        $visible = mb_substr($local, 0, 2);

        return $visible . str_repeat('*', max(1, mb_strlen($local) - 2)) . '@' . $domain;
    }
}
