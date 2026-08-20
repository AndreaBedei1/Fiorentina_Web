<?php

declare(strict_types=1);

/**
 * Verifica che la chiave API del calendario funzioni davvero.
 *
 *     php scripts/check-football-api.php
 *     php scripts/check-football-api.php LA_CHIAVE     (senza toccare il .env)
 *
 * Interroga il servizio una risorsa alla volta e mostra cosa risponde. Serve a
 * rispondere alla sola domanda che conta prima di fidarsi di un fornitore:
 * "con questa chiave, le partite della Fiorentina arrivano oppure no?".
 *
 * Non scrive niente a database: e una prova, non una sincronizzazione.
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Config;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$config = $app->get(Config::class);

$chiave = $argv[1] ?? $config->string('services.football.api_key');
$squadra = $config->string('services.football.team_name', 'Fiorentina');

Console::title('Verifica della chiave API del calendario');

if (trim($chiave) === '') {
    Console::error('Nessuna chiave da provare.');
    Console::line();
    Console::bullet('Richiedine una gratuita su https://www.football-data.org/client/register');
    Console::bullet('Poi mettila in FOOTBALL_API_KEY dentro il file .env,');
    Console::bullet('oppure passala qui: php scripts/check-football-api.php LA_CHIAVE');
    exit(1);
}

Console::bullet(sprintf('Chiave:  %s...%s (%d caratteri)', substr($chiave, 0, 4), substr($chiave, -2), strlen($chiave)));
Console::bullet(sprintf('Squadra: %s', $squadra));
Console::line();

/**
 * @return array{stato: int, corpo: array<string, mixed>|null, errore: string}
 */
function interroga(string $percorso, string $chiave): array
{
    $handle = curl_init('https://api.football-data.org/v4' . $percorso);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . $chiave, 'Accept: application/json'],
    ]);

    $corpo = curl_exec($handle);
    $stato = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $errore = curl_error($handle);
    curl_close($handle);

    return [
        'stato' => $stato,
        'corpo' => is_string($corpo) ? json_decode($corpo, true) : null,
        'errore' => $errore,
    ];
}

function esito(string $etichetta, array $risposta): bool
{
    $ok = $risposta['stato'] >= 200 && $risposta['stato'] < 300;

    if ($risposta['errore'] !== '') {
        Console::error(sprintf('%-42s errore di rete: %s', $etichetta, $risposta['errore']));

        return false;
    }

    if ($ok) {
        Console::success(sprintf('%-42s HTTP %d', $etichetta, $risposta['stato']));

        return true;
    }

    Console::error(sprintf(
        '%-42s HTTP %d - %s',
        $etichetta,
        $risposta['stato'],
        $risposta['corpo']['message'] ?? 'risposta non leggibile',
    ));

    return false;
}

$oggi = date('Y-m-d');
$fraQuattroMesi = date('Y-m-d', strtotime('+120 days'));

// 1. La chiave e valida?
$squadre = interroga('/competitions/SA/teams', $chiave);

if (! esito('Elenco squadre di Serie A', $squadre)) {
    Console::line();

    if ($squadre['stato'] === 400 || $squadre['stato'] === 403) {
        Console::warn('La chiave non e valida, oppure il piano non comprende la Serie A.');
        Console::bullet('Controlla di aver copiato il token per intero, senza spazi.');
    }

    exit(1);
}

// 2. Trovo la squadra.
$teamId = null;
foreach ($squadre['corpo']['teams'] ?? [] as $riga) {
    if (str_contains(mb_strtolower((string) ($riga['name'] ?? '')), mb_strtolower($squadra))) {
        $teamId = (int) $riga['id'];
        Console::bullet(sprintf('Trovata: %s (id %d)', $riga['name'], $teamId));
        break;
    }
}

if ($teamId === null) {
    Console::error(sprintf('"%s" non compare fra le squadre di Serie A restituite.', $squadra));
    exit(1);
}

Console::line();

// 3. La strada preferita: tutte le partite della squadra, coppe comprese.
$viaSquadra = interroga(
    sprintf('/teams/%d/matches?status=SCHEDULED,TIMED&dateFrom=%s&dateTo=%s&limit=20', $teamId, $oggi, $fraQuattroMesi),
    $chiave,
);

$partiteViaSquadra = esito('Partite della squadra (con le coppe)', $viaSquadra)
    ? ($viaSquadra['corpo']['matches'] ?? [])
    : [];

// 4. Il ripiego: le partite di Serie A, filtrate.
$viaCampionato = interroga(
    sprintf('/competitions/SA/matches?status=SCHEDULED,TIMED&dateFrom=%s&dateTo=%s', $oggi, $fraQuattroMesi),
    $chiave,
);

$partiteViaCampionato = [];

if (esito('Partite di Serie A (solo campionato)', $viaCampionato)) {
    foreach ($viaCampionato['corpo']['matches'] ?? [] as $partita) {
        $casa = mb_strtolower((string) ($partita['homeTeam']['name'] ?? ''));
        $ospite = mb_strtolower((string) ($partita['awayTeam']['name'] ?? ''));

        if (str_contains($casa, mb_strtolower($squadra)) || str_contains($ospite, mb_strtolower($squadra))) {
            $partiteViaCampionato[] = $partita;
        }
    }
}

Console::line();

$partite = $partiteViaSquadra !== [] ? $partiteViaSquadra : $partiteViaCampionato;
$strada = $partiteViaSquadra !== [] ? 'risorse della squadra' : 'risorse del campionato';

if ($partite === []) {
    Console::error('Nessuna partita restituita da nessuna delle due strade.');
    Console::bullet('Se le richieste sopra sono andate a buon fine, puo semplicemente non esserci');
    Console::bullet('nessuna partita in programma nei prossimi quattro mesi (pausa estiva).');
    exit(1);
}

Console::success(sprintf('%d partite in programma, lette dalle %s.', count($partite), $strada));
Console::line();

foreach (array_slice($partite, 0, 6) as $partita) {
    $inizio = new DateTimeImmutable((string) $partita['utcDate']);
    $inizio = $inizio->setTimezone(new DateTimeZone('Europe/Rome'));

    Console::bullet(sprintf(
        '%s  %-14s - %-14s  %s',
        $inizio->format('d/m/Y H:i'),
        $partita['homeTeam']['shortName'] ?? $partita['homeTeam']['name'] ?? '?',
        $partita['awayTeam']['shortName'] ?? $partita['awayTeam']['name'] ?? '?',
        $partita['competition']['name'] ?? '',
    ));
}

Console::line();

if ($partiteViaSquadra === []) {
    Console::warn('Le risorse della squadra non hanno dato risultati: il sito usera quelle del campionato.');
    Console::bullet('Conseguenza: compare la Serie A, non Coppa Italia e coppe europee.');
    Console::bullet('E il comportamento previsto, non un guasto: il sito lo gestisce da solo.');
    Console::line();
}

Console::success('La chiave funziona. Metti FOOTBALL_PROVIDER=football-data nel .env e lancia:');
Console::bullet('php scripts/sync-football.php');
exit(0);
