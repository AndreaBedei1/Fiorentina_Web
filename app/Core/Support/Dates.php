<?php

declare(strict_types=1);

namespace App\Core\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Formattazione delle date in italiano.
 *
 * Non usiamo IntlDateFormatter perché l'estensione intl non e garantita su
 * tutti i piani Aruba: le tabelle di traduzione sono poche righe e rendono il
 * comportamento identico ovunque.
 */
final class Dates
{
    private const MONTHS = [
        1 => 'gennaio', 2 => 'febbraio', 3 => 'marzo', 4 => 'aprile',
        5 => 'maggio', 6 => 'giugno', 7 => 'luglio', 8 => 'agosto',
        9 => 'settembre', 10 => 'ottobre', 11 => 'novembre', 12 => 'dicembre',
    ];

    private const MONTHS_SHORT = [
        1 => 'gen', 2 => 'feb', 3 => 'mar', 4 => 'apr',
        5 => 'mag', 6 => 'giu', 7 => 'lug', 8 => 'ago',
        9 => 'set', 10 => 'ott', 11 => 'nov', 12 => 'dic',
    ];

    private const DAYS = [
        0 => 'domenica', 1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì',
        4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato',
    ];

    private const DAYS_SHORT = [
        0 => 'dom', 1 => 'lun', 2 => 'mar', 3 => 'mer',
        4 => 'gio', 5 => 'ven', 6 => 'sab',
    ];

    /** Converte in DateTimeImmutable qualsiasi rappresentazione accettata dal progetto. */
    public static function parse(DateTimeInterface|string|int|null $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** 19 agosto 2026 */
    public static function long(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);

        if ($date === null) {
            return '';
        }

        return sprintf('%d %s %d', (int) $date->format('j'), self::MONTHS[(int) $date->format('n')], (int) $date->format('Y'));
    }

    /** mercoledì 19 agosto 2026 */
    public static function longWithWeekday(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);

        if ($date === null) {
            return '';
        }

        return self::DAYS[(int) $date->format('w')] . ' ' . self::long($date);
    }

    /** 19 ago 2026 */
    public static function short(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);

        if ($date === null) {
            return '';
        }

        return sprintf('%d %s %d', (int) $date->format('j'), self::MONTHS_SHORT[(int) $date->format('n')], (int) $date->format('Y'));
    }

    /** 19/08/2026 */
    public static function numeric(DateTimeInterface|string|null $value): string
    {
        return self::parse($value)?->format('d/m/Y') ?? '';
    }

    /** 19/08/2026 15:30 */
    public static function numericWithTime(DateTimeInterface|string|null $value): string
    {
        return self::parse($value)?->format('d/m/Y H:i') ?? '';
    }

    /** 15:30 */
    public static function time(DateTimeInterface|string|null $value): string
    {
        return self::parse($value)?->format('H:i') ?? '';
    }

    /** 19 agosto 2026 alle 15:30 */
    public static function longWithTime(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);

        if ($date === null) {
            return '';
        }

        return self::long($date) . ' alle ' . $date->format('H:i');
    }

    /** Formato ISO 8601, richiesto da `<time datetime>` e dai dati strutturati. */
    public static function iso(DateTimeInterface|string|null $value): string
    {
        return self::parse($value)?->format(DateTimeInterface::ATOM) ?? '';
    }

    public static function isoDate(DateTimeInterface|string|null $value): string
    {
        return self::parse($value)?->format('Y-m-d') ?? '';
    }

    /** Giorno del mese come numero (per i badge del calendario). */
    public static function day(DateTimeInterface|string|null $value): string
    {
        return self::parse($value)?->format('j') ?? '';
    }

    /** Mese abbreviato (per i badge del calendario). */
    public static function monthShort(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);

        return $date === null ? '' : self::MONTHS_SHORT[(int) $date->format('n')];
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? '';
    }

    public static function weekdayShort(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);

        return $date === null ? '' : self::DAYS_SHORT[(int) $date->format('w')];
    }

    /** "fra 3 giorni", "2 ore fa": usato nella dashboard amministrativa. */
    public static function relative(DateTimeInterface|string|null $value, ?DateTimeImmutable $now = null): string
    {
        $date = self::parse($value);

        if ($date === null) {
            return '';
        }

        $now ??= new DateTimeImmutable();
        $seconds = $date->getTimestamp() - $now->getTimestamp();
        $future = $seconds > 0;
        $seconds = abs($seconds);

        $label = match (true) {
            $seconds < 60 => 'pochi secondi',
            $seconds < 3600 => self::plural((int) round($seconds / 60), 'minuto', 'minuti'),
            $seconds < 86400 => self::plural((int) round($seconds / 3600), 'ora', 'ore'),
            $seconds < 2592000 => self::plural((int) round($seconds / 86400), 'giorno', 'giorni'),
            $seconds < 31536000 => self::plural((int) round($seconds / 2592000), 'mese', 'mesi'),
            default => self::plural((int) round($seconds / 31536000), 'anno', 'anni'),
        };

        return $future ? 'fra ' . $label : $label . ' fa';
    }

    public static function isToday(DateTimeInterface|string|null $value, ?DateTimeImmutable $now = null): bool
    {
        $date = self::parse($value);

        if ($date === null) {
            return false;
        }

        return $date->format('Y-m-d') === ($now ?? new DateTimeImmutable())->format('Y-m-d');
    }

    public static function isPast(DateTimeInterface|string|null $value, ?DateTimeImmutable $now = null): bool
    {
        $date = self::parse($value);

        return $date !== null && $date < ($now ?? new DateTimeImmutable());
    }

    /**
     * Converte una data e un orario provenienti da due input HTML separati
     * (`<input type="date">` + `<input type="time">`) nel formato DATETIME MySQL.
     */
    public static function combineToDatabase(?string $date, ?string $time): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $time = ($time === null || trim($time) === '') ? '00:00' : trim($time);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i', trim($date) . ' ' . $time);

        if ($parsed === false) {
            $parsed = self::parse(trim($date) . ' ' . $time);
        }

        return $parsed?->format('Y-m-d H:i:s');
    }

    public static function toDatabase(DateTimeInterface|string|null $value): ?string
    {
        return self::parse($value)?->format('Y-m-d H:i:s');
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }
}
