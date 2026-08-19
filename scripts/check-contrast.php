<?php

declare(strict_types=1);

/**
 * Verifica i rapporti di contrasto del design system secondo WCAG 2.1.
 *
 *     php scripts/check-contrast.php
 *
 * La combinazione viola/rosso/bianco richiesta e bella ma insidiosa: il rosso
 * su bianco e il viola chiaro su fondo scuro sono esattamente i casi in cui si
 * scende sotto soglia senza accorgersene. Questo script controlla ogni
 * accostamento effettivamente usato nell interfaccia e fallisce se qualcosa
 * non raggiunge il livello richiesto.
 *
 * Soglie WCAG 2.1 AA:
 *   4.5:1  testo normale
 *   3.0:1  testo grande (>= 24px, oppure >= 18.66px in grassetto)
 *   3.0:1  componenti di interfaccia e bordi significativi
 */

use App\Console\Console;

require __DIR__ . '/bootstrap.php';

/** Palette del design system, allineata a tailwind.config.js. */
const PALETTE = [
    'viola-50' => '#f6f3fb', 'viola-100' => '#ece4f7', 'viola-200' => '#d8c9ee',
    'viola-300' => '#bda3e0', 'viola-400' => '#9d76ce', 'viola-500' => '#8151b8',
    'viola-600' => '#6b3aa0', 'viola-700' => '#582d84', 'viola-800' => '#41215f',
    'viola-900' => '#2c1640', 'viola-950' => '#1a0d27',

    'rosso-50' => '#fef2f3', 'rosso-100' => '#fde3e6', 'rosso-200' => '#fbccd2',
    'rosso-300' => '#f7a4af', 'rosso-400' => '#f07387', 'rosso-500' => '#e34460',
    'rosso-600' => '#cd2247', 'rosso-700' => '#ac173a', 'rosso-800' => '#901637',
    'rosso-900' => '#7b1634', 'rosso-950' => '#450718',

    'sabbia-50' => '#faf9f7', 'sabbia-100' => '#f3f1ed', 'sabbia-200' => '#e7e3dc',
    'sabbia-300' => '#d5cec3', 'sabbia-400' => '#b8ae9e', 'sabbia-500' => '#9c9081',
    'sabbia-600' => '#7a6f63', 'sabbia-700' => '#6b6157', 'sabbia-800' => '#57504a',
    'sabbia-900' => '#3d3833', 'sabbia-950' => '#231f1c',

    'bianco' => '#ffffff',
];

/**
 * Accostamenti effettivamente presenti nell'interfaccia.
 *
 * @var list<array{0: string, 1: string, 2: string, 3: float}> testo, fondo, dove, soglia
 */
const COMBINAZIONI = [
    // --- Sito pubblico, fondo chiaro ---
    ['viola-900', 'sabbia-50', 'Titoli sul fondo del sito', 4.5],
    ['viola-900', 'bianco', 'Titoli su card bianche', 4.5],
    ['sabbia-800', 'bianco', 'Testo corrente su card', 4.5],
    ['sabbia-700', 'sabbia-50', 'Testo secondario', 4.5],
    ['sabbia-600', 'bianco', 'Testo di supporto', 4.5],
    ['rosso-700', 'bianco', 'Collegamenti e sopratitoli', 4.5],
    ['rosso-700', 'sabbia-50', 'Sopratitoli di sezione', 4.5],
    ['viola-800', 'bianco', 'Pulsanti secondari', 4.5],
    ['viola-800', 'viola-50', 'Testo su riquadri viola chiari', 4.5],

    // --- Testo bianco su fondi pieni ---
    ['bianco', 'viola-800', 'Testo su intestazione e pulsanti', 4.5],
    ['bianco', 'viola-900', 'Testo su sezioni viola scure', 4.5],
    ['bianco', 'viola-950', 'Testo sul piede di pagina', 4.5],
    ['bianco', 'rosso-600', 'Testo sul pulsante di accento', 4.5],
    ['bianco', 'rosso-700', 'Testo sul pulsante di accento premuto', 4.5],

    // --- Testo chiaro su fondo scuro ---
    ['viola-100', 'viola-900', 'Testo di apertura', 4.5],
    ['viola-100', 'viola-950', 'Menu del pannello', 4.5],
    ['viola-200', 'viola-900', 'Sottotitoli su fondo scuro', 4.5],
    ['viola-300', 'viola-950', 'Testo minore nel piede di pagina', 4.5],

    // --- Badge dell'area amministrativa ---
    ['viola-800', 'viola-50', 'Badge informativo', 4.5],
    ['rosso-800', 'rosso-50', 'Badge di errore', 4.5],
    ['sabbia-800', 'sabbia-100', 'Badge neutro', 4.5],

    // --- Elementi grafici: soglia 3:1 ---
    ['rosso-600', 'bianco', 'Anello di focus e barre attive', 3.0],
    ['rosso-500', 'viola-900', 'Indicatore di voce attiva', 3.0],
    ['viola-600', 'bianco', 'Bordi dei campi in focus', 3.0],
    ['sabbia-500', 'bianco', 'Bordi dei campi a riposo', 3.0],
];

/** @return array{0: float, 1: float, 2: float} */
function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        (float) hexdec(substr($hex, 0, 2)),
        (float) hexdec(substr($hex, 2, 2)),
        (float) hexdec(substr($hex, 4, 2)),
    ];
}

/** Luminanza relativa secondo la formula WCAG. */
function relativeLuminance(string $hex): float
{
    $channels = array_map(static function (float $value): float {
        $value /= 255;

        return $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }, hexToRgb($hex));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(string $foreground, string $background): float
{
    $first = relativeLuminance($foreground);
    $second = relativeLuminance($background);

    $lighter = max($first, $second);
    $darker = min($first, $second);

    return ($lighter + 0.05) / ($darker + 0.05);
}

Console::title('Verifica contrasti WCAG 2.1');

$failures = 0;
$warnings = 0;

printf("%-46s %-22s %8s  %-6s %s\n", 'DOVE', 'COLORI', 'RAPPORTO', 'SOGLIA', 'ESITO');
echo str_repeat('-', 100), "\n";

foreach (COMBINAZIONI as [$foreground, $background, $where, $threshold]) {
    if (! isset(PALETTE[$foreground], PALETTE[$background])) {
        Console::error(sprintf('Colore sconosciuto nella combinazione "%s".', $where));
        $failures++;

        continue;
    }

    $ratio = contrastRatio(PALETTE[$foreground], PALETTE[$background]);
    $passes = $ratio >= $threshold;

    // Il livello AAA (7:1) non e un requisito, ma segnalarlo aiuta a capire
    // quali accostamenti hanno margine e quali sono al limite.
    $level = match (true) {
        $ratio >= 7.0 => 'AAA',
        $ratio >= 4.5 => 'AA',
        $ratio >= 3.0 => 'AA grande',
        default => 'insufficiente',
    };

    if (! $passes) {
        $failures++;
    } elseif ($ratio < $threshold * 1.05) {
        $warnings++;
    }

    printf(
        "%-46s %-22s %7.2f:1  %-6.1f %s %s\n",
        mb_substr($where, 0, 45),
        $foreground . ' / ' . $background,
        $ratio,
        $threshold,
        $passes ? 'OK ' : 'KO ',
        $level,
    );
}

echo str_repeat('-', 100), "\n";
Console::line();

if ($failures > 0) {
    Console::error(sprintf('%d combinazioni sotto soglia: correggere la palette prima di pubblicare.', $failures));
    exit(1);
}

Console::success(sprintf('Tutte le %d combinazioni rispettano WCAG 2.1 AA.', count(COMBINAZIONI)));

if ($warnings > 0) {
    Console::warn(sprintf('%d combinazioni sono vicine alla soglia: attenzione se si modificano i colori.', $warnings));
}

exit(0);
