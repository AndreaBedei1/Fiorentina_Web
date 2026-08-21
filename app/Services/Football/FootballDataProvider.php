<?php

declare(strict_types=1);

namespace App\Services\Football;

use App\Core\Support\HttpClient;
use App\DTO\FootballMatchData;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Fornitore basato su football-data.org, versione 4.
 *
 * E il servizio consigliato per questo sito: il piano gratuito comprende la
 * Serie A e non scade, con un limite di dieci richieste al minuto. Il cron ne
 * fa due per esecuzione, due volte al giorno.
 *
 * L'identificativo della squadra non va cercato a mano: se manca, lo si chiede
 * al servizio filtrando le squadre di Serie A per nome. Costa una richiesta in
 * piu la prima volta, ed evita a chi installa il sito di andare a caccia di un
 * numero su una pagina di documentazione.
 *
 * DUE STRADE PER LE STESSE PARTITE
 *
 * La documentazione non dice quali risorse comprenda il piano gratuito, e la
 * risposta si scopre solo con una chiave in mano. C'e un motivo concreto per
 * dubitare: /teams/{id}/matches restituisce le partite di TUTTE le competizioni
 * a cui la squadra partecipa, comprese Coppa Italia e coppe europee, che nel
 * piano gratuito non ci sono. Il servizio potrebbe rifiutare l'intera richiesta.
 *
 * Quindi si prova prima quella strada, che e la piu ricca, e se non arriva
 * nulla si ripiega su /competitions/SA/matches filtrando la Fiorentina: la
 * Serie A e sicuramente compresa nel piano gratuito, quindi quel percorso
 * funziona in ogni caso. Si perdono le coppe, si tiene il campionato.
 */
final class FootballDataProvider implements FootballApiInterface
{
    private const BASE_URL = 'https://api.football-data.org/v4';

    /** Codice della Serie A nel catalogo del servizio. */
    private const CAMPIONATO = 'SA';

    /**
     * Ampiezza della finestra temporale, in giorni, entro cui cercare le
     * partite. Serve all'endpoint del campionato, che senza limiti di data
     * restituirebbe tutte le 380 partite della stagione.
     */
    private const GIORNI_FINESTRA = 120;

    private ?int $teamIdRisolto = null;

    /** Vero quando l'endpoint della squadra si e gia rivelato inutilizzabile. */
    private bool $soloCampionato = false;

    /** Perche l'ultima lettura non ha dato niente. Vedi FootballApiInterface. */
    private ?string $ultimoErrore = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly int $teamId = 0,
        private readonly string $teamName = 'Fiorentina',
        private readonly ?DateTimeImmutable $riferimento = null,
    ) {
    }

    private function adesso(): DateTimeImmutable
    {
        return $this->riferimento ?? new DateTimeImmutable();
    }

    public function name(): string
    {
        return 'football-data';
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /** @return list<FootballMatchData> */
    public function fetchUpcomingMatches(int $limit = 10): array
    {
        $adesso = $this->adesso();

        // SCHEDULED e TIMED sono entrambe "da giocare": la seconda indica che
        // l'orario e ormai confermato. Chiederne una sola perderebbe meta
        // calendario.
        $partite = $this->cerca('SCHEDULED,TIMED', $adesso, $adesso->modify('+' . self::GIORNI_FINESTRA . ' days'), $limit);

        usort($partite, static fn (FootballMatchData $a, FootballMatchData $b) => $a->kickoffAt <=> $b->kickoffAt);

        return array_slice($partite, 0, $limit);
    }

    /** @return list<FootballMatchData> */
    public function fetchRecentResults(int $limit = 10): array
    {
        $adesso = $this->adesso();

        $partite = $this->cerca('FINISHED', $adesso->modify('-' . self::GIORNI_FINESTRA . ' days'), $adesso, $limit);

        // Il servizio le restituisce dalla piu vecchia: qui servono al
        // contrario, perche in pagina si mostrano gli ultimi risultati.
        usort($partite, static fn (FootballMatchData $a, FootballMatchData $b) => $b->kickoffAt <=> $a->kickoffAt);

        return array_slice($partite, 0, $limit);
    }

    /**
     * Cerca le partite, prima fra quelle della squadra e poi, se necessario,
     * fra quelle di Serie A.
     *
     * @return list<FootballMatchData>
     */
    private function cerca(string $stati, DateTimeImmutable $da, DateTimeImmutable $a, int $limit): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $query = [
            'status' => $stati,
            'dateFrom' => $da->format('Y-m-d'),
            'dateTo' => $a->format('Y-m-d'),
            'limit' => max(1, min(100, $limit)),
        ];

        if (! $this->soloCampionato) {
            $teamId = $this->risolviTeamId();

            if ($teamId !== null) {
                $partite = $this->leggiElenco(sprintf('/teams/%d/matches?%s', $teamId, http_build_query($query)));

                if ($partite !== []) {
                    return $partite;
                }

                // Puo essere una settimana senza partite, oppure l'endpoint
                // fuori dal piano. In entrambi i casi conviene provare la
                // strada del campionato prima di arrendersi; se anche quella
                // e vuota, vuol dire che partite non ce ne sono.
                $this->logger->info('API calcio: nessuna partita dalle risorse della squadra, provo con quelle del campionato.');
            }
        }

        $partite = $this->leggiElenco('/competitions/' . self::CAMPIONATO . '/matches?' . http_build_query($query));

        return array_values(array_filter($partite, fn (FootballMatchData $p): bool => $this->riguardaLaSquadra($p)));
    }

    /**
     * @return list<FootballMatchData>
     */
    private function leggiElenco(string $percorso): array
    {
        $risposta = $this->richiesta($percorso);

        if ($risposta === null) {
            return [];
        }

        $partite = [];

        foreach ($risposta['matches'] ?? [] as $partita) {
            if (! is_array($partita)) {
                continue;
            }

            $letta = $this->leggiPartita($partita);

            if ($letta !== null) {
                $partite[] = $letta;
            }
        }

        return $partite;
    }

    /**
     * L'elenco del campionato contiene tutte le squadre: qui teniamo solo le
     * partite che riguardano la nostra.
     */
    private function riguardaLaSquadra(FootballMatchData $partita): bool
    {
        $cercata = $this->normalizza($this->teamName);

        return str_contains($this->normalizza($partita->homeTeam), $cercata)
            || str_contains($this->normalizza($partita->awayTeam), $cercata);
    }

    /**
     * Identificativo della squadra: quello configurato, oppure cercato per
     * nome fra le squadre di Serie A.
     */
    private function risolviTeamId(): ?int
    {
        if ($this->teamId > 0) {
            return $this->teamId;
        }

        if ($this->teamIdRisolto !== null) {
            return $this->teamIdRisolto;
        }

        $risposta = $this->richiesta('/competitions/' . self::CAMPIONATO . '/teams');

        if ($risposta === null) {
            return null;
        }

        $cercato = $this->normalizza($this->teamName);

        foreach ($risposta['teams'] ?? [] as $squadra) {
            if (! is_array($squadra) || ! isset($squadra['id'])) {
                continue;
            }

            foreach (['name', 'shortName', 'tla'] as $campo) {
                $valore = $this->normalizza((string) ($squadra[$campo] ?? ''));

                if ($valore !== '' && str_contains($valore, $cercato)) {
                    $this->teamIdRisolto = (int) $squadra['id'];

                    $this->logger->info('Squadra trovata sul fornitore calcio.', [
                        'nome' => $squadra['name'] ?? null,
                        'id' => $this->teamIdRisolto,
                    ]);

                    return $this->teamIdRisolto;
                }
            }
        }

        $this->logger->error('Squadra non trovata fra quelle di Serie A.', ['cercata' => $this->teamName]);

        return null;
    }

    /** @return array<string, mixed>|null */
    private function richiesta(string $percorso): ?array
    {
        $url = self::BASE_URL . $percorso;

        $risposta = $this->http->getJson($url, [
            'X-Auth-Token: ' . $this->apiKey,
            'Accept: application/json',
        ]);

        if ($risposta === null) {
            $this->logger->warning('API calcio: nessuna risposta utilizzabile.', ['url' => $url]);
            $this->ultimoErrore = $this->http->lastFailure() ?? 'il fornitore non ha risposto';

            return null;
        }

        // Chiave errata, limite superato o piano insufficiente arrivano tutti
        // in questa forma, con un messaggio leggibile: vale la pena riportarlo
        // nei log invece di un generico fallimento.
        if (isset($risposta['errorCode']) || isset($risposta['message'])) {
            $this->logger->error('API calcio: risposta di errore.', [
                'codice' => $risposta['errorCode'] ?? null,
                'messaggio' => $risposta['message'] ?? null,
            ]);
            $this->ultimoErrore = trim((string) ($risposta['message'] ?? '')) !== ''
                ? 'il fornitore ha risposto: ' . (string) $risposta['message']
                : 'il fornitore ha rifiutato la richiesta';

            return null;
        }

        return $risposta;
    }

    public function lastError(): ?string
    {
        return $this->ultimoErrore;
    }

    /** @param array<string, mixed> $partita */
    private function leggiPartita(array $partita): ?FootballMatchData
    {
        $casa = $partita['homeTeam'] ?? null;
        $ospite = $partita['awayTeam'] ?? null;

        if (! is_array($casa) || ! is_array($ospite)) {
            return null;
        }

        try {
            // Gli orari arrivano in UTC: senza conversione una partita delle
            // 20:45 comparirebbe alle 18:45.
            $inizio = (new DateTimeImmutable((string) ($partita['utcDate'] ?? 'now')))
                ->setTimezone(new \DateTimeZone('Europe/Rome'));
        } catch (\Exception) {
            return null;
        }

        $competizione = $partita['competition'] ?? [];
        $punteggio = $partita['score']['fullTime'] ?? [];

        return new FootballMatchData(
            externalId: (string) ($partita['id'] ?? ''),
            competition: (string) ($competizione['name'] ?? 'Competizione'),
            homeTeam: (string) ($casa['shortName'] ?? $casa['name'] ?? ''),
            awayTeam: (string) ($ospite['shortName'] ?? $ospite['name'] ?? ''),
            kickoffAt: $inizio,
            status: $this->traduciStato((string) ($partita['status'] ?? 'SCHEDULED')),
            competitionCode: isset($competizione['code']) ? (string) $competizione['code'] : null,
            roundLabel: $this->etichettaTurno($partita),
            season: $this->stagione($partita),
            homeTeamLogo: isset($casa['crest']) ? (string) $casa['crest'] : null,
            awayTeamLogo: isset($ospite['crest']) ? (string) $ospite['crest'] : null,
            venue: isset($partita['venue']) && is_string($partita['venue']) ? $partita['venue'] : null,
            homeScore: isset($punteggio['home']) && $punteggio['home'] !== null ? (int) $punteggio['home'] : null,
            awayScore: isset($punteggio['away']) && $punteggio['away'] !== null ? (int) $punteggio['away'] : null,
            // TIMED = ora confermata, SCHEDULED = solo la data. Le partite
            // gia giocate hanno ovviamente un orario reale.
            timeConfirmed: (string) ($partita['status'] ?? '') !== 'SCHEDULED',
        );
    }

    /** @param array<string, mixed> $partita */
    private function etichettaTurno(array $partita): ?string
    {
        if (isset($partita['matchday']) && $partita['matchday'] !== null) {
            return sprintf('%d%s giornata', (int) $partita['matchday'], "\u{00AA}");
        }

        // Nelle coppe la giornata non c'e: al suo posto arriva la fase.
        $fase = (string) ($partita['stage'] ?? '');

        return match ($fase) {
            'FINAL' => 'Finale',
            'SEMI_FINALS' => 'Semifinale',
            'QUARTER_FINALS' => 'Quarti di finale',
            'LAST_16' => 'Ottavi di finale',
            'LAST_32' => 'Sedicesimi di finale',
            'GROUP_STAGE' => 'Fase a gironi',
            'PLAYOFFS' => 'Spareggi',
            default => null,
        };
    }

    /** @param array<string, mixed> $partita */
    private function stagione(array $partita): ?int
    {
        $inizio = $partita['season']['startDate'] ?? null;

        if (! is_string($inizio) || $inizio === '') {
            return null;
        }

        return (int) substr($inizio, 0, 4);
    }

    /** Traduce gli stati del servizio nei quattro che usa il sito. */
    private function traduciStato(string $stato): string
    {
        return match ($stato) {
            'IN_PLAY', 'PAUSED' => 'live',
            'FINISHED', 'AWARDED' => 'finished',
            'POSTPONED', 'SUSPENDED' => 'postponed',
            'CANCELLED' => 'cancelled',
            default => 'scheduled',
        };
    }

    private function normalizza(string $valore): string
    {
        return mb_strtolower(trim($valore));
    }
}
