<?php

declare(strict_types=1);

namespace App\Console;

/** Piccole utilita di output per gli script CLI: colori, titoli, tabelle. */
final class Console
{
    private static bool $colorsEnabled = true;

    public static function disableColors(): void
    {
        self::$colorsEnabled = false;
    }

    public static function title(string $text): void
    {
        self::line('');
        self::line(self::paint('  ' . strtoupper($text) . '  ', '45;97'));
        self::line('');
    }

    public static function success(string $text): void
    {
        self::line(self::paint('OK', '42;30') . ' ' . $text);
    }

    public static function info(string $text): void
    {
        self::line(self::paint('..', '44;97') . ' ' . $text);
    }

    public static function warn(string $text): void
    {
        self::line(self::paint('!!', '43;30') . ' ' . $text);
    }

    public static function error(string $text): void
    {
        fwrite(STDERR, self::paint('KO', '41;97') . ' ' . $text . PHP_EOL);
    }

    public static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public static function bullet(string $text): void
    {
        self::line('   ' . $text);
    }

    private static function paint(string $text, string $code): string
    {
        return self::$colorsEnabled ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
