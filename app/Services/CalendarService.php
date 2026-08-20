<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\FootballMatch;
use App\Repositories\EventRepository;
use App\Services\Football\FootballService;
use DateTimeImmutable;

/**
 * Calendario unificato: partite della Fiorentina più appuntamenti del gruppo.
 *
 * Le due fonti restano distinte a database (le partite arrivano da un'API, gli
 * eventi li scrivono gli amministratori) e vengono unite solo al momento della
 * visualizzazione, in voci con un tipo esplicito. Cosi il template può
 * distinguerle graficamente senza dover indovinare da cosa sta guardando.
 */
final class CalendarService
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly FootballService $football,
    ) {
    }

    /**
     * Voci di un mese, ordinate per data.
     *
     * @return list<CalendarEntry>
     */
    public function entriesForMonth(int $year, int $month): array
    {
        $start = (new DateTimeImmutable())->setDate($year, $month, 1)->setTime(0, 0);
        $end = $start->modify('+1 month');

        return $this->mergeAndSort(
            $this->events->betweenDates($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')),
            $this->football->matchesBetween($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')),
        );
    }

    /**
     * Prossimi appuntamenti in ordine cronologico, di qualunque tipo.
     *
     * E la vista predefinita su smartphone: una griglia mensile su schermo
     * piccolo diventa illeggibile, mentre un elenco funziona sempre.
     *
     * @return list<CalendarEntry>
     */
    public function upcoming(int $limit = 12): array
    {
        $entries = $this->mergeAndSort(
            $this->events->upcoming($limit),
            $this->football->upcomingMatches($limit),
        );

        return array_slice($entries, 0, $limit);
    }

    /**
     * Voci raggruppate per giorno, per la griglia mensile.
     *
     * @return array<string, list<CalendarEntry>> Chiave nel formato Y-m-d.
     */
    public function entriesByDay(int $year, int $month): array
    {
        $grouped = [];

        foreach ($this->entriesForMonth($year, $month) as $entry) {
            $grouped[$entry->startsAt->format('Y-m-d')][] = $entry;
        }

        return $grouped;
    }

    /**
     * Struttura della griglia mensile: settimane da lunedì a domenica,
     * completate con i giorni dei mesi adiacenti.
     *
     * @return list<list<array{date: DateTimeImmutable, inMonth: bool, entries: list<CalendarEntry>}>>
     */
    public function monthGrid(int $year, int $month): array
    {
        $entriesByDay = $this->entriesByDay($year, $month);

        $firstOfMonth = (new DateTimeImmutable())->setDate($year, $month, 1)->setTime(0, 0);
        $daysInMonth = (int) $firstOfMonth->format('t');

        // In Italia la settimana inizia di lunedì: 'N' vale 1 per lunedì.
        $leadingDays = (int) $firstOfMonth->format('N') - 1;
        $gridStart = $firstOfMonth->modify(sprintf('-%d days', $leadingDays));

        $totalCells = (int) (ceil(($leadingDays + $daysInMonth) / 7) * 7);

        $weeks = [];
        $week = [];

        for ($i = 0; $i < $totalCells; $i++) {
            $date = $gridStart->modify(sprintf('+%d days', $i));

            $week[] = [
                'date' => $date,
                'inMonth' => (int) $date->format('n') === $month,
                'entries' => $entriesByDay[$date->format('Y-m-d')] ?? [],
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return $weeks;
    }

    /**
     * @param list<Event>         $events
     * @param list<FootballMatch> $matches
     * @return list<CalendarEntry>
     */
    private function mergeAndSort(array $events, array $matches): array
    {
        $entries = [];

        foreach ($events as $event) {
            $entries[] = CalendarEntry::fromEvent($event);
        }

        foreach ($matches as $match) {
            $entries[] = CalendarEntry::fromMatch($match);
        }

        usort(
            $entries,
            static fn (CalendarEntry $a, CalendarEntry $b): int => $a->startsAt <=> $b->startsAt,
        );

        return $entries;
    }

    /** @return array{year: int, month: int} Mese valido a partire dai parametri di query. */
    public function normalizeMonth(?int $year, ?int $month): array
    {
        $now = new DateTimeImmutable();

        $year ??= (int) $now->format('Y');
        $month ??= (int) $now->format('n');

        // Limiti prudenziali: evitano date assurde passate via URL.
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));

        return ['year' => $year, 'month' => $month];
    }
}
