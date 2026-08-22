<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Event;
use App\Models\News;
use App\Models\Photo;
use App\Models\ProductImage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il testo alternativo se lo calcola il sito.
 *
 * Agli amministratori non si chiede piu di scriverlo. Non e pigrizia: e che a
 * un campo che nessuno sa compilare si risponde con "immagine", "foto",
 * "logo", e un alt del genere e peggio di niente per chi ascolta la pagina.
 * Il titolo della notizia, il nome del prodotto e il nome dell'album, invece,
 * il sito li conosce gia e descrivono davvero cosa si sta guardando.
 *
 * Questi test tengono ferma la regola: qualunque immagine di contenuto ha un
 * alt, e quell'alt non e mai vuoto.
 */
final class TestoAlternativoTest extends TestCase
{
    #[Test]
    public function la_notizia_si_annuncia_col_proprio_titolo(): void
    {
        $notizia = News::fromRow([
            'id' => 1,
            'title' => 'Trasferta di Milano, un pullman e mezzo',
            'slug' => 'trasferta-milano',
            'body' => '<p>Testo.</p>',
        ]);

        self::assertSame('Trasferta di Milano, un pullman e mezzo', $notizia->imageAlt());
    }

    #[Test]
    public function l_evento_si_annuncia_col_proprio_titolo(): void
    {
        $evento = Event::fromRow([
            'id' => 1,
            'title' => 'Cena sociale di fine anno',
            'slug' => 'cena-sociale',
            'starts_at' => '2026-12-12 20:00:00',
        ]);

        self::assertSame('Cena sociale di fine anno', $evento->imageAlt());
    }

    #[Test]
    public function il_prodotto_si_annuncia_col_proprio_nome(): void
    {
        $immagine = ProductImage::fromRow([
            'id' => 1,
            'product_id' => 1,
            'storage_key' => '2026/08/abc',
            'extension' => 'jpg',
        ]);

        self::assertSame('Felpa con cappuccio', $immagine->alt('Felpa con cappuccio'));
    }

    /** Una fotografia di galleria si annuncia con l'album che la contiene. */
    #[Test]
    public function la_fotografia_si_annuncia_col_nome_dell_album(): void
    {
        $foto = Photo::fromRow([
            'id' => 1,
            'album_id' => 1,
            'storage_key' => '2026/08/abc',
            'extension' => 'jpg',
            'album_title' => 'Fiorentina-Juventus 2026',
        ]);

        self::assertStringContainsString('Fiorentina-Juventus 2026', $foto->alt());
    }

    /**
     * Anche una fotografia sciolta, senza album, dice qualcosa.
     *
     * Non capita dall'interfaccia - una foto nasce sempre dentro un album -
     * ma se capitasse, meglio "Fotografia di Baraonda Fiorentina" che il
     * silenzio.
     */
    #[Test]
    public function la_fotografia_senza_album_non_resta_muta(): void
    {
        $foto = Photo::fromRow([
            'id' => 1,
            'album_id' => 1,
            'storage_key' => '2026/08/abc',
            'extension' => 'jpg',
        ]);

        self::assertNotSame('', trim($foto->alt()));
    }
}
