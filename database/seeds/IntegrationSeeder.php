<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Football\FootballService;
use App\Services\Social\SocialService;

/**
 * Popola calendario partite e contenuti social usando i fornitori configurati.
 *
 * Senza chiavi API entrano in gioco i fornitori dimostrativi, quindi il sito
 * risulta completo fin da subito. Con le chiavi reali configurate, lo stesso
 * seed scarica i dati veri: e esattamente la stessa strada che percorre il cron.
 */
final class IntegrationSeeder extends Seeder
{
    public function name(): string
    {
        return 'Calendario partite e contenuti social';
    }

    public function run(): int
    {
        $total = 0;

        $football = $this->app->get(FootballService::class)->sync();
        $this->say('Calcio: ' . $football->summary());
        $total += $football->upcomingCount + $football->resultsCount;

        foreach ($football->errors as $error) {
            $this->say('  ! ' . $error);
        }

        $social = $this->app->get(SocialService::class)->sync();
        $this->say('Social: ' . $social->summary());
        $total += $social->total();

        return $total;
    }
}
