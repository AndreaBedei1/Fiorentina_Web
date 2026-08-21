<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\Config;
use App\Core\Session\Session;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\ProductRepository;

/**
 * Carrello basato sulla sessione.
 *
 * In sessione teniamo soltanto identificativi e quantita: prezzi, nomi e
 * disponibilità si rileggono a ogni richiesta dal database. Cosi un prezzo
 * modificato dall'amministratore vale subito, e nessuno può manipolare gli
 * importi agendo sui dati del proprio browser.
 *
 * Il visitatore non ha e non deve avere un account: il carrello vive per la
 * durata della sessione e finisce con l'invio dell'ordine.
 */
final class CartService
{
    public function __construct(
        private readonly Session $session,
        private readonly ProductRepository $products,
        private readonly Config $config,
    ) {
    }

    private function sessionKey(): string
    {
        return $this->config->string('shop.cart.session_key', 'cart');
    }

    /** @return array<string, array{product_id: int, variant_id: int|null, quantity: int}> */
    private function raw(): array
    {
        $cart = $this->session->get($this->sessionKey(), []);

        return is_array($cart) ? $cart : [];
    }

    /** @param array<string, array{product_id: int, variant_id: int|null, quantity: int}> $cart */
    private function persist(array $cart): void
    {
        $this->session->put($this->sessionKey(), $cart);
    }

    private static function lineKey(int $productId, ?int $variantId): string
    {
        return $productId . ':' . ($variantId ?? 0);
    }

    // -----------------------------------------------------------------------
    //  Modifica
    // -----------------------------------------------------------------------

    /**
     * Aggiunge un articolo al carrello.
     *
     * @return CartActionResult Esito con messaggio già pronto per l'utente.
     */
    public function add(int $productId, ?int $variantId, int $quantity): CartActionResult
    {
        $product = $this->products->find($productId);

        if ($product === null) {
            return CartActionResult::failure('Il prodotto richiesto non e disponibile.');
        }

        $variant = null;

        if ($product->hasVariants()) {
            if ($variantId === null) {
                return CartActionResult::failure('Devi indicare la taglia prima di aggiungere al carrello.');
            }

            $variant = $this->products->findVariant($variantId, $productId);

            if ($variant === null) {
                return CartActionResult::failure('La taglia indicata non esiste.');
            }
        } else {
            // Ignoriamo una scelta indicata per un prodotto che non ne ha:
            // e sempre un parametro manipolato o un residuo di navigazione.
            $variantId = null;
        }

        $maxPerItem = $this->config->int('shop.cart.max_quantity_per_item', 20);
        $maxItems = $this->config->int('shop.cart.max_items', 30);

        $quantity = max(1, min($maxPerItem, $quantity));

        $cart = $this->raw();
        $key = self::lineKey($productId, $variantId);

        if (isset($cart[$key])) {
            $cart[$key]['quantity'] = min($maxPerItem, $cart[$key]['quantity'] + $quantity);
        } else {
            if (count($cart) >= $maxItems) {
                return CartActionResult::failure(sprintf(
                    'Il carrello può contenere al massimo %d articoli diversi.',
                    $maxItems,
                ));
            }

            $cart[$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity];
        }

        $this->persist($cart);

        // "Aggiunto" o "aggiunta"? Dipende dal nome del prodotto, che non
        // conosciamo: la forma con i due punti evita del tutto la concordanza.
        return CartActionResult::success(sprintf(
            'Hai aggiunto al carrello: %s%s.',
            $product->name,
            $variant !== null ? ' - taglia ' . $variant->label : '',
        ));
    }

    public function updateQuantity(string $lineKey, int $quantity): CartActionResult
    {
        $cart = $this->raw();

        if (! isset($cart[$lineKey])) {
            return CartActionResult::failure('Articolo non presente nel carrello.');
        }

        if ($quantity <= 0) {
            unset($cart[$lineKey]);
            $this->persist($cart);

            return CartActionResult::success('Articolo rimosso dal carrello.');
        }

        $cart[$lineKey]['quantity'] = min($this->config->int('shop.cart.max_quantity_per_item', 20), $quantity);
        $this->persist($cart);

        return CartActionResult::success('Quantita aggiornata.');
    }

    public function remove(string $lineKey): CartActionResult
    {
        $cart = $this->raw();

        if (! isset($cart[$lineKey])) {
            return CartActionResult::failure('Articolo non presente nel carrello.');
        }

        unset($cart[$lineKey]);
        $this->persist($cart);

        return CartActionResult::success('Articolo rimosso dal carrello.');
    }

    public function clear(): void
    {
        $this->session->forget($this->sessionKey());
    }

    // -----------------------------------------------------------------------
    //  Lettura
    // -----------------------------------------------------------------------

    /**
     * Contenuto del carrello con prezzi ricalcolati.
     *
     * Le righe che nel frattempo non sono più acquistabili (prodotto ritirato,
     * variante esaurita) vengono segnalate e rimosse: meglio dirlo qui che al
     * momento della conferma.
     */
    public function contents(): CartContents
    {
        $cart = $this->raw();

        if ($cart === []) {
            return new CartContents([], [], 0.0, 0);
        }

        $lines = [];
        $removedMessages = [];
        $subtotal = 0.0;
        $totalQuantity = 0;
        $changed = false;

        foreach ($cart as $key => $entry) {
            $product = $this->products->find((int) $entry['product_id']);

            if ($product === null) {
                $removedMessages[] = 'Un articolo non più disponibile è stato rimosso dal carrello.';
                unset($cart[$key]);
                $changed = true;

                continue;
            }

            $variant = null;

            if ($entry['variant_id'] !== null) {
                $variant = $this->products->findVariant((int) $entry['variant_id'], $product->id);

                if ($variant === null) {
                    $removedMessages[] = sprintf(
                        'La scelta fatta per "%s" non è più disponibile: l\'articolo è stato tolto dal carrello.',
                        $product->name,
                    );
                    unset($cart[$key]);
                    $changed = true;

                    continue;
                }
            }

            // Il prezzo non dipende dalla scelta: una XXL costa quanto una S.
            $unitPrice = $product->price;
            $quantity = max(1, (int) $entry['quantity']);
            $lineTotal = round($unitPrice * $quantity, 2);

            $lines[] = new CartLine(
                key: (string) $key,
                product: $product,
                variant: $variant,
                quantity: $quantity,
                unitPrice: $unitPrice,
                lineTotal: $lineTotal,
            );

            $subtotal += $lineTotal;
            $totalQuantity += $quantity;
        }

        if ($changed) {
            $this->persist($cart);
        }

        return new CartContents($lines, $removedMessages, round($subtotal, 2), $totalQuantity);
    }

    /** Numero di articoli, per il contatore nell'intestazione del sito. */
    public function itemCount(): int
    {
        $total = 0;

        foreach ($this->raw() as $entry) {
            $total += max(0, (int) ($entry['quantity'] ?? 0));
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }
}
