<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ProductRepository;
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
    private ProductRepository $prodotti;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->prodotti = self::app()->get(ProductRepository::class);
    }

    #[Test]
    public function le_collisioni_ricevono_un_suffisso_progressivo(): void
    {
        $slugs = [];

        for ($i = 0; $i < 3; $i++) {
            $id = $this->prodotti->create([
                'name' => 'Sciarpa storica',
                'slug' => 'sciarpa-storica',
            ]);

            $slugs[] = $this->prodotti->find($id)?->slug;
        }

        $this->assertSame('sciarpa-storica', $slugs[0]);
        $this->assertSame('sciarpa-storica-2', $slugs[1]);
        $this->assertSame('sciarpa-storica-3', $slugs[2]);
        $this->assertSame(3, count(array_unique($slugs)));
    }

    #[Test]
    public function aggiornare_un_contenuto_non_cambia_il_suo_slug(): void
    {
        $id = $this->prodotti->create([
            'name' => 'Nome originale',
            'slug' => 'nome-originale',
        ]);

        // Aggiornando lo stesso record, lo slug non deve prendere il suffisso
        // per collisione con se stesso.
        $this->prodotti->update($id, ['slug' => 'nome-originale', 'name' => 'Nome aggiornato']);

        $this->assertSame('nome-originale', $this->prodotti->find($id)?->slug);
    }

    #[Test]
    public function il_vincolo_unico_e_attivo_a_database(): void
    {
        $this->prodotti->create(['name' => 'Uno', 'slug' => 'slug-unico']);

        $this->expectException(\PDOException::class);

        // Inserimento diretto, aggirando la generazione dello slug.
        $this->db->insertInto('products', [
            'name' => 'Due',
            'slug' => 'slug-unico',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
