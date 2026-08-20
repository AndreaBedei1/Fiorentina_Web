<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\SettingsService;

/** Crea le impostazioni mancanti senza toccare quelle già configurate. */
final class SettingsSeeder extends Seeder
{
    public function name(): string
    {
        return 'Impostazioni del sito';
    }

    public function run(): int
    {
        $created = $this->app->get(SettingsService::class)->ensureDefaults();

        $this->say($created === 0
            ? 'Tutte le impostazioni erano già presenti.'
            : sprintf('%d\'impostazioni create.', $created));

        return $created;
    }
}
