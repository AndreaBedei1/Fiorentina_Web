<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ProductRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Le fotografie di un prodotto.
 *
 * Un prodotto senza fotografia non si puo mostrare: a catalogo sarebbe un
 * cartellino con niente sopra, e chi ordina comprerebbe alla cieca. La regola
 * "almeno una" vive in due punti - il pannello, che nasconde il cestino
 * quando ne resta una sola, e il controller, che rifiuta comunque - e qui si
 * verifica cio su cui entrambi si appoggiano.
 */
final class ImmaginiProdottoTest extends IntegrationTestCase
{
    private ProductRepository $prodotti;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateAll();
        $this->prodotti = self::app()->get(ProductRepository::class);
    }

    #[Test]
    public function un_prodotto_appena_creato_non_ha_fotografie(): void
    {
        self::assertSame(0, $this->prodotti->countImages($this->creaProdotto()));
    }

    #[Test]
    public function le_fotografie_si_contano(): void
    {
        $id = $this->creaProdotto();
        $this->aggiungiFoto($id, 'a', true);
        $this->aggiungiFoto($id, 'b');

        self::assertSame(2, $this->prodotti->countImages($id));
    }

    /**
     * Eliminata la principale, la principale diventa un'altra.
     *
     * Senza questo passaggio il prodotto resterebbe con delle fotografie ma
     * senza nessuna eletta a copertina: a catalogo comparirebbe comunque la
     * prima della lista, ma nel pannello sparirebbe la stellina e non si
     * capirebbe piu quale si sta mostrando.
     */
    #[Test]
    public function eliminando_la_principale_un_altra_prende_il_suo_posto(): void
    {
        $id = $this->creaProdotto();
        $principale = $this->aggiungiFoto($id, 'a', true);
        $seconda = $this->aggiungiFoto($id, 'b');

        $this->prodotti->deleteImage($id, $principale);

        $rimaste = $this->db->select(
            'SELECT id, is_primary FROM product_images WHERE product_id = :p',
            ['p' => $id],
        );

        self::assertCount(1, $rimaste);
        self::assertSame($seconda, (int) $rimaste[0]['id']);
        self::assertSame(1, (int) $rimaste[0]['is_primary']);
    }

    /** Eliminando una qualsiasi, la principale resta quella di prima. */
    #[Test]
    public function eliminando_una_secondaria_la_principale_non_cambia(): void
    {
        $id = $this->creaProdotto();
        $principale = $this->aggiungiFoto($id, 'a', true);
        $seconda = $this->aggiungiFoto($id, 'b');

        $this->prodotti->deleteImage($id, $seconda);

        self::assertSame(
            $principale,
            (int) $this->db->scalar('SELECT id FROM product_images WHERE product_id = :p AND is_primary = 1', ['p' => $id]),
        );
    }

    /**
     * L'id dell'immagine arriva dall'indirizzo, e un indirizzo si scrive a mano.
     *
     * Chi provasse a togliere la fotografia di un altro prodotto non ottiene
     * niente: nessuna riga eliminata, nessun file cancellato.
     */
    #[Test]
    public function non_si_puo_togliere_la_fotografia_di_un_altro_prodotto(): void
    {
        $mio = $this->creaProdotto('Sciarpa');
        $altrui = $this->creaProdotto('Cappellino');
        $suaFoto = $this->aggiungiFoto($altrui, 'x', true);

        self::assertNull($this->prodotti->deleteImage($mio, $suaFoto));
        self::assertSame(1, $this->prodotti->countImages($altrui));
    }

    // -----------------------------------------------------------------------

    private function creaProdotto(string $nome = 'Prodotto di prova'): int
    {
        return $this->prodotti->create([
            'category_id' => null,
            'name' => $nome,
            'short_description' => null,
            'description' => '',
            'price' => 10.0,
            'created_by' => $this->createSuperAdmin()->id,
        ]);
    }

    private function aggiungiFoto(int $prodotto, string $chiave, bool $principale = false): int
    {
        return $this->prodotti->addImage([
            'product_id' => $prodotto,
            'storage_key' => '2026/08/' . $chiave,
            'extension' => 'jpg',
            'sort_order' => $this->prodotti->countImages($prodotto),
            'is_primary' => $principale ? 1 : 0,
        ]);
    }
}
