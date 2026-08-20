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

/**
 * La palette si legge da tailwind.config.js, che ne e la fonte unica.
 *
 * Ricopiarla qui sembrava più semplice, ed e durato finché qualcuno non ha
 * cambiato un colore da una parte sola: il controllo continuava a passare
 * misurando tinte che il sito non usava più. Un controllo che valida la copia
 * sbagliata e peggio di nessun controllo.
 *
 * @return array<string, string>
 */
function leggiPalette(string $percorso): array
{
    $sorgente = (string) file_get_contents($percorso);

    if (preg_match('/colors:\s*\{(.*?)
            \},/s', $sorgente, $blocco) !== 1) {
        Console::error('Non riesco a leggere la sezione "colors" di tailwind.config.js.');
        exit(1);
    }

    preg_match_all(
        '/(?<famiglia>[a-z]+):\s*\{(?<gradini>[^}]*)\}/s',
        $blocco[1],
        $famiglie,
        PREG_SET_ORDER,
    );

    $palette = ['bianco' => '#ffffff'];

    foreach ($famiglie as $famiglia) {
        preg_match_all("/(?<gradino>\d+):\s*'(?<valore>#[0-9a-fA-F]{6})'/", $famiglia['gradini'], $tinte, PREG_SET_ORDER);

        foreach ($tinte as $tinta) {
            $palette[$famiglia['famiglia'] . '-' . $tinta['gradino']] = strtolower($tinta['valore']);
        }
    }

    return $palette;
}

$palette = leggiPalette(dirname(__DIR__) . '/tailwind.config.js');

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
    if (! isset($palette[$foreground], $palette[$background])) {
        Console::error(sprintf('Colore sconosciuto nella combinazione "%s".', $where));
        $failures++;

        continue;
    }

    $ratio = contrastRatio($palette[$foreground], $palette[$background]);
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
