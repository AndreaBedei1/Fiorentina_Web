<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/**
 * Richiesta d'ordine del merchandising.
 *
 * Il sito non incassa nulla: registra la richiesta, avvisa il responsabile e
 * invia al cliente le istruzioni per il pagamento offline. Non esiste, e non
 * deve esistere, alcun campo relativo a carte o pagamenti elettronici.
 *
 * Il totale comprende i soli articoli. La spedizione non ha un costo noto al
 * momento dell'ordine: viene concordata al telefono insieme al pagamento,
 * quindi qui non compare come importo.
 */
final class Order
{
    use CastsRowValues;

    /*
     * Due stati soli: c'e ancora da fare qualcosa, oppure no.
     *
     * Prima erano otto e descrivevano il lavoro di un negozio con magazzino e
     * corriere. Qui arriva una richiesta, qualcuno telefona, ci si accorda, e
     * quando la roba e nelle mani della persona l'ordine e chiuso: le caselle
     * in mezzo nessuno le avrebbe aggiornate, e uno stato non aggiornato dice
     * il falso.
     */
    public const STATUS_NEW = 'NEW';
    public const STATUS_COMPLETED = 'COMPLETED';

    /** @param list<OrderItem> $items */
    public function __construct(
        public readonly int $id,
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly string $customerFirstName,
        public readonly string $customerLastName,
        public readonly string $customerEmail,
        public readonly string $customerPhone,
        public readonly string $shippingAddress,
        public readonly string $shippingPostalCode,
        public readonly string $shippingCity,
        public readonly string $shippingProvince,
        public readonly string $shippingCountry,
        public readonly float $subtotal,
        public readonly float $total,
        public readonly int $itemsCount,
        public readonly array $items,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<OrderItem>      $items
     */
    public static function fromRow(array $row, array $items = []): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            orderNumber: self::castString($row, 'order_number'),
            status: self::castString($row, 'status', self::STATUS_NEW),
            customerFirstName: self::castString($row, 'customer_first_name'),
            customerLastName: self::castString($row, 'customer_last_name'),
            customerEmail: self::castString($row, 'customer_email'),
            customerPhone: self::castString($row, 'customer_phone'),
            shippingAddress: self::castString($row, 'shipping_address'),
            shippingPostalCode: self::castString($row, 'shipping_postal_code'),
            shippingCity: self::castString($row, 'shipping_city'),
            shippingProvince: self::castString($row, 'shipping_province'),
            shippingCountry: self::castString($row, 'shipping_country', 'IT'),
            subtotal: self::castFloat($row, 'subtotal'),
            total: self::castFloat($row, 'total'),
            itemsCount: self::castInt($row, 'items_count'),
            items: $items,
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function customerName(): string
    {
        return trim($this->customerFirstName . ' ' . $this->customerLastName);
    }

    /** Indirizzo di spedizione su una riga sola, per email ed elenchi. */
    public function shippingLine(): string
    {
        $parts = array_filter([
            $this->shippingAddress,
            trim($this->shippingPostalCode . ' ' . $this->shippingCity),
            $this->shippingProvince !== '' ? '(' . $this->shippingProvince . ')' : null,
        ]);

        return implode(', ', array_map('trim', $parts));
    }

    public static function statusLabelFor(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETED => 'Completato',
            default => 'Da gestire',
        };
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }

    /** Classi del badge di stato. Mappa esplicita: Tailwind non genera classi dinamiche. */
    public function statusClasses(): string
    {
        return $this->isCompleted()
            ? 'bg-emerald-100 text-emerald-900 ring-emerald-200'
            : 'bg-rosso-100 text-rosso-800 ring-rosso-200';
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** @return list<string> */
    public static function allStatuses(): array
    {
        return [self::STATUS_NEW, self::STATUS_COMPLETED];
    }
}
