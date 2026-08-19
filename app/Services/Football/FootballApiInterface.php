<?php

declare(strict_types=1);

namespace App\Services\Football;

use App\DTO\FootballMatchData;

/**
 * Contratto verso un fornitore di dati calcistici.
 *
 * Deliberatamente minimo: il sito ha bisogno del calendario e dei risultati
 * della Fiorentina, nient altro. Restando piccola, l'interfaccia consente di
 * cambiare fornitore scrivendo una sola classe, senza toccare controller,
 * template o database.
 */
interface FootballApiInterface
{
    /**
     * Prossime partite in programma.
     *
     * @return list<FootballMatchData>
     */
    public function fetchUpcomingMatches(int $limit = 10): array;

    /**
     * Partite gia disputate, dalla piu recente.
     *
     * @return list<FootballMatchData>
     */
    public function fetchRecentResults(int $limit = 10): array;

    /** Nome del fornitore, salvato a database per riconoscere l'origine del dato. */
    public function name(): string;

    /** Indica se il fornitore e utilizzabile (per esempio se la chiave e presente). */
    public function isConfigured(): bool;
}
