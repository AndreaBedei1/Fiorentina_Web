<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;

/**
 * Riga d'ordine.
 *
 * Nome, scelta e prezzo sono copiati al momento della conferma: se il
 * prodotto viene poi modificato o rimosso, l'ordine storico resta fedele.
 * Anche il nome della scelta viene copiato, perche "M" da solo non dice se
 * era una taglia o una misura.
 */
final class OrderItem
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly ?int $productId,
        public readonly ?int $variantId,
        public readonly string $productName,
        public readonly ?string $variantOption,
        public readonly ?string $variantLabel,
        public readonly ?string $imageKey,
        public readonly float $unitPrice,
        public readonly int $quantity,
        public readonly float $lineTotal,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            orderId: self::castInt($row, 'order_id'),
            productId: self::castNullableInt($row, 'product_id'),
            variantId: self::castNullableInt($row, 'variant_id'),
            productName: self::castString($row, 'product_name'),
            variantOption: self::castNullableString($row, 'variant_option'),
            variantLabel: self::castNullableString($row, 'variant_label'),
            imageKey: self::castNullableString($row, 'image_key'),
            unitPrice: self::castFloat($row, 'unit_price'),
            quantity: self::castInt($row, 'quantity', 1),
            lineTotal: self::castFloat($row, 'line_total'),
        );
    }

    /**
     * Il pezzo di indirizzo della scheda pubblica del prodotto.
     *
     * Serve a chi prepara l'ordine per andare a vedere di che articolo si
     * tratta senza cercarlo a mano: fra due magliette con nomi simili si fa
     * presto a sbagliare scatolone.
     *
     * Il nome qui e la copia fatta al momento dell'ordine, quindi la coda
     * dell'indirizzo potrebbe non combaciare piu se il prodotto e stato nel
     * frattempo rinominato: conta il numero, e il sito reindirizza da solo
     * alla forma giusta. Se invece il prodotto e stato eliminato non c'e piu
     * niente da mostrare, e il nome resta scritto senza collegamento.
     */
    public function productUrlKey(): ?string
    {
        if ($this->productId === null) {
            return null;
        }

        $coda = Str::slug($this->productName);

        return $coda === '' ? (string) $this->productId : $this->productId . '-' . $coda;
    }

    public function displayName(): string
    {
        return $this->variantLabel !== null
            ? $this->productName . ' - ' . $this->choiceLabel()
            : $this->productName;
    }

    /**
     * "Taglia M", oppure la sola etichetta per gli ordini registrati prima
     * che il nome della scelta venisse conservato.
     */
    public function choiceLabel(): string
    {
        if ($this->variantLabel === null) {
            return '';
        }

        return $this->variantOption !== null
            ? $this->variantOption . ' ' . $this->variantLabel
            : $this->variantLabel;
    }
}
