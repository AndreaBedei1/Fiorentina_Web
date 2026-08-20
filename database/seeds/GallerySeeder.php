<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Console;
use App\Core\Support\Str;
use App\Models\Album;
use App\Models\User;
use App\Repositories\AlbumRepository;
use App\Repositories\UserRepository;
use App\Services\Media\PhotoService;

/**
 * Album e fotografie dimostrative.
 *
 * Le immagini passano dalla vera pipeline (validazione, orientamento,
 * ridimensionamento, filigrana, WebP): il seed non popola solo il database, ma
 * verifica che l'elaborazione funzioni davvero sulla macchina in uso.
 */
final class GallerySeeder extends Seeder
{
    private const ALBUMS = [
        ['Stadio Franchi, stagione in corso', 'stadio', -14, 'Le immagini dalla curva nelle partite casalinghe.', 4],
        ['Trasferta di Milano', 'trasferte', -40, 'Il viaggio, il settore ospiti e il rientro.', 3],
        ['Cena sociale', 'eventi', -85, 'Oltre cento soci a tavola per la cena annuale.', 3],
        ['Raduno estivo', 'raduni', -160, 'Il ritrovo di inizio stagione.', 2],
    ];

    public function name(): string
    {
        return 'Galleria fotografica';
    }

    public function run(): int
    {
        if ($this->tableHasRows('albums')) {
            $this->say('Album già presenti: salto.');

            return 0;
        }

        $albums = $this->app->get(AlbumRepository::class);
        $author = $this->resolveAuthor();
        $created = 0;

        $canGenerateImages = DemoImageFactory::isAvailable() && $author !== null;

        if (! $canGenerateImages) {
            Console::warn('Immagini dimostrative non generabili: creo solo gli album.');
        }

        $factory = $canGenerateImages
            ? new DemoImageFactory($this->app->storagePath('temp'))
            : null;

        $photoService = $canGenerateImages ? $this->app->get(PhotoService::class) : null;
        $seed = 1;

        foreach (self::ALBUMS as [$title, $category, $daysAgo, $description, $photoCount]) {
            $date = date('Y-m-d', strtotime(sprintf('%+d days', $daysAgo)));

            $albumId = $albums->create([
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => $description . ' [album dimostrativo]',
                'event_date' => $date,
                'year' => (int) substr($date, 0, 4),
                'category' => $category,
                'status' => Album::STATUS_PUBLISHED,
                'sort_order' => $created,
                'meta_description' => $description,
                'created_by' => $author?->id,
            ]);

            $created++;

            if ($factory === null || $photoService === null || $author === null) {
                continue;
            }

            $files = [];

            for ($i = 0; $i < $photoCount; $i++) {
                // Alterniamo orizzontali e verticali: la galleria deve reggere
                // entrambi gli orientamenti, ed e bene vederlo subito.
                $isPortrait = $i % 3 === 2;

                $files[] = $factory->create(
                    $title,
                    $seed++,
                    $isPortrait ? 1000 : 1600,
                    $isPortrait ? 1400 : 1067,
                );
            }

            $report = $photoService->uploadToAlbum($albumId, $files, $author);

            $this->say(sprintf('  %s: %s', $title, $report->summaryMessage()));

            foreach ($report->errors as $error) {
                Console::warn('    ' . $error);
            }
        }

        $factory?->cleanup();

        $this->say(sprintf('%d album creati.', $created));

        return $created;
    }

    private function resolveAuthor(): ?User
    {
        $users = $this->app->get(UserRepository::class);

        foreach ($users->all() as $user) {
            if ($user->isSuperAdmin()) {
                return $user;
            }
        }

        return $users->all()[0] ?? null;
    }
}
