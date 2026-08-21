<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\AlbumRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Unicita degli slug.
 *
 * Due contenuti con lo stesso slug renderebbero uno dei due irraggiungibile:
 * il vincolo unico a database lo impedisce, e la generazione automatica deve
 * risolvere le collisioni senza far fallire il salvataggio.
 */
final class SlugTest extends IntegrationTestCase
{
    private AlbumRepository $albums;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->albums = self::app()->get(AlbumRepository::class);
    }

    #[Test]
    public function le_collisioni_ricevono_un_suffisso_progressivo(): void
    {
        $slugs = [];

        for ($i = 0; $i < 3; $i++) {
            $id = $this->albums->create([
                'title' => 'Cena sociale 2026',
                'slug' => 'cena-sociale-2026',
            ]);

            $slugs[] = $this->albums->find($id)?->slug;
        }

        $this->assertSame('cena-sociale-2026', $slugs[0]);
        $this->assertSame('cena-sociale-2026-2', $slugs[1]);
        $this->assertSame('cena-sociale-2026-3', $slugs[2]);
        $this->assertSame(3, count(array_unique($slugs)));
    }

    #[Test]
    public function aggiornare_un_contenuto_non_cambia_il_suo_slug(): void
    {
        $id = $this->albums->create([
            'title' => 'Titolo originale',
            'slug' => 'titolo-originale',
        ]);

        // Aggiornando lo stesso record, lo slug non deve prendere il suffisso
        // per collisione con se stesso.
        $this->albums->update($id, ['slug' => 'titolo-originale', 'title' => 'Titolo aggiornato']);

        $this->assertSame('titolo-originale', $this->albums->find($id)?->slug);
    }

    #[Test]
    public function il_vincolo_unico_e_attivo_a_database(): void
    {
        $this->albums->create(['title' => 'Uno', 'slug' => 'slug-unico']);

        $this->expectException(\PDOException::class);

        // Inserimento diretto, aggirando la generazione dello slug.
        $this->db->insertInto('albums', [
            'title' => 'Due',
            'slug' => 'slug-unico',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
