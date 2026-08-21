<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Order;
use App\Models\Product;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\Shop\CartService;
use App\Services\Shop\OrderService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Carrello e richieste d'ordine.
 *
 * Il punto piu delicato e il calcolo dei totali: viene fatto interamente lato
 * server a partire dai prezzi correnti, quindi nessun valore inviato dal
 * browser puo influenzare l'importo finale. Questi test lo verificano
 * esplicitamente, incluso il caso in cui qualcuno provi a manipolarlo.
 */
final class CartAndOrderTest extends IntegrationTestCase
{
    private CartService $cart;

    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->startSession();
        $this->resetSession();

        $this->cart = self::app()->make(CartService::class);
        $this->products = self::app()->get(ProductRepository::class);

        // Le impostazioni del negozio devono esistere: i totali dipendono da loro.
        self::app()->get(\App\Services\SettingsService::class)->ensureDefaults();
    }

    private function createProduct(float $price = 18.00, array $overrides = []): int
    {
        return $this->products->create(array_merge([
            'name' => 'Sciarpa di prova',
            'slug' => 'sciarpa-di-prova-' . bin2hex(random_bytes(3)),
            'short_description' => 'Descrizione breve',
            'price' => $price,
            'availability' => Product::AVAILABILITY_IN_STOCK,
            'status' => Product::STATUS_PUBLISHED,
            'track_quantity' => 0,
        ], $overrides));
    }

    #[Test]
    public function aggiunge_un_prodotto_e_calcola_il_subtotale(): void
    {
        $id = $this->createProduct(18.00);

        $this->assertTrue($this->cart->add($id, null, 2)->successful);

        $contents = $this->cart->contents();

        $this->assertSame(1, $contents->lineCount());
        $this->assertSame(2, $contents->totalQuantity);
        $this->assertSame(36.00, $contents->subtotal);
    }

    #[Test]
    public function il_prezzo_arriva_dal_database_non_dalla_richiesta(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        // Il prezzo cambia dopo che l articolo e gia nel carrello.
        $this->products->update($id, ['price' => 25.00]);

        // In sessione ci sono solo identificativi: il totale si ricalcola.
        $this->assertSame(25.00, $this->cart->contents()->subtotal);
    }

    #[Test]
    public function un_prodotto_non_pubblicato_non_puo_essere_aggiunto(): void
    {
        $id = $this->createProduct(18.00, ['status' => Product::STATUS_DRAFT]);

        $result = $this->cart->add($id, null, 1);

        $this->assertTrue($result->failed());
        $this->assertTrue($this->cart->isEmpty());
    }

    #[Test]
    public function un_prodotto_esaurito_non_puo_essere_aggiunto(): void
    {
        $id = $this->createProduct(18.00, ['availability' => Product::AVAILABILITY_OUT_OF_STOCK]);

        $this->assertTrue($this->cart->add($id, null, 1)->failed());
    }

    #[Test]
    public function la_quantita_e_limitata(): void
    {
        $id = $this->createProduct(10.00);
        $max = self::app()->config()->int('shop.cart.max_quantity_per_item', 20);

        $this->cart->add($id, null, 9999);

        $this->assertSame($max, $this->cart->contents()->totalQuantity);
    }

    #[Test]
    public function un_prodotto_con_varianti_richiede_la_scelta_della_variante(): void
    {
        $id = $this->createProduct(22.00, ['track_quantity' => 1, 'quantity' => 0]);

        $this->products->replaceVariants($id, [
            ['label' => 'M', 'quantity' => 5, 'is_available' => true, 'price_modifier' => 0],
            ['label' => 'XXL', 'quantity' => 2, 'is_available' => true, 'price_modifier' => 2.0],
        ]);

        $senzaVariante = $this->cart->add($id, null, 1);

        $this->assertTrue($senzaVariante->failed());
        $this->assertStringContainsString('taglia', $senzaVariante->message);
    }

    #[Test]
    public function la_variante_modifica_il_prezzo(): void
    {
        $id = $this->createProduct(22.00, ['track_quantity' => 1, 'quantity' => 0]);

        $this->products->replaceVariants($id, [
            ['label' => 'XXL', 'quantity' => 3, 'is_available' => true, 'price_modifier' => 2.0],
        ]);

        $product = $this->products->find($id);
        $variant = $product?->variants[0] ?? null;

        $this->assertNotNull($variant);
        $this->assertTrue($this->cart->add($id, $variant->id, 1)->successful);
        $this->assertSame(24.00, $this->cart->contents()->subtotal);
    }

    #[Test]
    public function un_articolo_rimosso_dal_catalogo_esce_dal_carrello(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        $this->products->delete($id);

        $contents = $this->cart->contents();

        $this->assertTrue($contents->isEmpty());
        $this->assertNotEmpty($contents->notices);
    }

    #[Test]
    public function l_ordine_registra_totali_e_snapshot_dei_prodotti(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 2);

        $request = $this->fakeRequest('POST', '/ordine');
        $orders = self::app()->make(OrderService::class);

        $result = $orders->placeOrder([
            'first_name' => 'Marco',
            'last_name' => 'Bianchi',
            'email' => 'marco@example.test',
            'phone' => '055 1234567',
            'address' => 'Via del Campo 12',
            'postal_code' => '50100',
            'city' => 'Firenze',
            'province' => 'FI',
        ], $request);

        $this->assertTrue($result->successful, (string) $result->error);

        $order = $result->order;

        $this->assertNotNull($order);
        $this->assertSame(36.00, $order->subtotal);
        // La spedizione non entra nel totale: si concorda dopo, al telefono.
        $this->assertSame(36.00, $order->total);
        $this->assertSame(2, $order->itemsCount);
        $this->assertSame(Order::STATUS_NEW, $order->status);

        // Snapshot: il nome del prodotto e copiato nella riga d ordine.
        $this->assertCount(1, $order->items);
        $this->assertSame('Sciarpa di prova', $order->items[0]->productName);
        $this->assertSame(18.00, $order->items[0]->unitPrice);
    }

    #[Test]
    public function il_numero_d_ordine_segue_il_formato_progressivo(): void
    {
        $id = $this->createProduct(10.00);
        $orders = self::app()->make(OrderService::class);
        $request = $this->fakeRequest('POST', '/ordine');

        $numeri = [];

        for ($i = 0; $i < 3; $i++) {
            $this->cart->add($id, null, 1);

            $result = $orders->placeOrder([
                'first_name' => 'Cliente',
                'last_name' => 'Numero ' . $i,
                'email' => 'cliente' . $i . '@example.test',
                'phone' => '055 1234567',
                'address' => 'Via del Campo 12', 'postal_code' => '50100',
                'city' => 'Firenze', 'province' => 'FI',
            ], $request);

            $numeri[] = $result->order?->orderNumber;
        }

        $anno = date('Y');

        $this->assertSame('BF-' . $anno . '-000001', $numeri[0]);
        $this->assertSame('BF-' . $anno . '-000002', $numeri[1]);
        $this->assertSame('BF-' . $anno . '-000003', $numeri[2]);
        $this->assertSame(3, count(array_unique($numeri)), 'I numeri d ordine devono essere unici.');
    }

    #[Test]
    public function lo_snapshot_sopravvive_all_eliminazione_del_prodotto(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        $orders = self::app()->make(OrderService::class);
        $result = $orders->placeOrder([
            'first_name' => 'Marco', 'last_name' => 'Bianchi',
            'email' => 'marco@example.test', 'phone' => '055 1234567',
            'address' => 'Via del Campo 12', 'postal_code' => '50100',
            'city' => 'Firenze', 'province' => 'FI',
        ], $this->fakeRequest('POST', '/ordine'));

        $orderId = $result->order?->id;
        $this->assertNotNull($orderId);

        // Eliminazione definitiva del prodotto, non solo soft delete.
        $this->db->statement('DELETE FROM products WHERE id = ?', [$id]);

        $reloaded = self::app()->get(OrderRepository::class)->find($orderId);

        $this->assertNotNull($reloaded);
        $this->assertCount(1, $reloaded->items);
        $this->assertSame('Sciarpa di prova', $reloaded->items[0]->productName);
        $this->assertNull($reloaded->items[0]->productId, 'Il riferimento al prodotto deve diventare nullo.');
    }

    #[Test]
    public function il_carrello_si_svuota_dopo_l_invio_dell_ordine(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        self::app()->make(OrderService::class)->placeOrder([
            'first_name' => 'Marco', 'last_name' => 'Bianchi',
            'email' => 'marco@example.test', 'phone' => '055 1234567',
            'address' => 'Via del Campo 12', 'postal_code' => '50100',
            'city' => 'Firenze', 'province' => 'FI',
        ], $this->fakeRequest('POST', '/ordine'));

        $this->assertTrue($this->cart->isEmpty());
    }

    #[Test]
    public function il_cambio_di_stato_viene_registrato_nello_storico(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        $orders = self::app()->make(OrderService::class);
        $result = $orders->placeOrder([
            'first_name' => 'Marco', 'last_name' => 'Bianchi',
            'email' => 'marco@example.test', 'phone' => '055 1234567',
            'address' => 'Via del Campo 12', 'postal_code' => '50100',
            'city' => 'Firenze', 'province' => 'FI',
        ], $this->fakeRequest('POST', '/ordine'));

        $orderId = (int) $result->order?->id;
        $admin = $this->createUser();

        $this->assertTrue($orders->updateStatus($orderId, Order::STATUS_PAID_OFFLINE, $admin, 'Bonifico ricevuto'));

        $history = self::app()->get(OrderRepository::class)->statusHistory($orderId);

        // Prima voce: creazione. Seconda: il cambio appena effettuato.
        $this->assertCount(2, $history);
        $this->assertSame(Order::STATUS_PAID_OFFLINE, $history[1]['to_status']);
        $this->assertSame('Bonifico ricevuto', $history[1]['note']);
    }

    #[Test]
    public function uno_stato_non_previsto_viene_rifiutato(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        $orders = self::app()->make(OrderService::class);
        $result = $orders->placeOrder([
            'first_name' => 'Marco', 'last_name' => 'Bianchi',
            'email' => 'marco@example.test', 'phone' => '055 1234567',
            'address' => 'Via del Campo 12', 'postal_code' => '50100',
            'city' => 'Firenze', 'province' => 'FI',
        ], $this->fakeRequest('POST', '/ordine'));

        $admin = $this->createUser();

        $this->assertFalse(
            $orders->updateStatus((int) $result->order?->id, 'STATO_INVENTATO', $admin),
        );
    }

    #[Test]
    public function un_ordine_archiviato_resta_nel_database(): void
    {
        $id = $this->createProduct(18.00);
        $this->cart->add($id, null, 1);

        $result = self::app()->make(OrderService::class)->placeOrder([
            'first_name' => 'Marco', 'last_name' => 'Bianchi',
            'email' => 'marco@example.test', 'phone' => '055 1234567',
            'address' => 'Via del Campo 12', 'postal_code' => '50100',
            'city' => 'Firenze', 'province' => 'FI',
        ], $this->fakeRequest('POST', '/ordine'));

        $orderId = (int) $result->order?->id;
        $repository = self::app()->get(OrderRepository::class);

        $repository->delete($orderId);

        // Sparisce dagli elenchi...
        $this->assertNull($repository->find($orderId));

        // ...ma la riga c e ancora: un ordine non si perde per un clic.
        $this->assertSame(1, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM orders WHERE id = ? AND deleted_at IS NOT NULL',
            [$orderId],
        ));
    }

    #[Test]
    public function non_si_puo_inviare_un_ordine_con_il_carrello_vuoto(): void
    {
        $result = self::app()->make(OrderService::class)->placeOrder([
            'first_name' => 'Marco', 'last_name' => 'Bianchi',
            'email' => 'marco@example.test', 'phone' => '055 1234567',
            'address' => 'Via del Campo 12', 'postal_code' => '50100',
            'city' => 'Firenze', 'province' => 'FI',
        ], $this->fakeRequest('POST', '/ordine'));

        $this->assertTrue($result->failed());
    }
}
