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
 */
final class Order
{
    use CastsRowValues;

    public const STATUS_NEW = 'NEW';
    public const STATUS_CONTACTED = 'CONTACTED';
    public const STATUS_WAITING_PAYMENT = 'WAITING_PAYMENT';
    public const STATUS_PAID_OFFLINE = 'PAID_OFFLINE';
    public const STATUS_PREPARING = 'PREPARING';
    public const STATUS_SHIPPED = 'SHIPPED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Ordine di avanzamento tipico, usato per la timeline nel pannello. */
    public const STATUS_FLOW = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_WAITING_PAYMENT,
        self::STATUS_PAID_OFFLINE,
        self::STATUS_PREPARING,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
    ];

    /** @param list<OrderItem> $items */
    public function __construct(
        public readonly int $id,
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly string $customerFirstName,
        public readonly string $customerLastName,
        public readonly string $customerEmail,
        public readonly string $customerPhone,
        public readonly string $shippingMethod,
        public readonly ?string $shippingAddress,
        public readonly ?string $shippingPostalCode,
        public readonly ?string $shippingCity,
        public readonly ?string $shippingProvince,
        public readonly string $shippingCountry,
        public readonly ?string $notes,
        public readonly ?string $adminNotes,
        public readonly float $subtotal,
        public readonly float $shippingCost,
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
            shippingMethod: self::castString($row, 'shipping_method', 'delivery'),
            shippingAddress: self::castNullableString($row, 'shipping_address'),
            shippingPostalCode: self::castNullableString($row, 'shipping_postal_code'),
            shippingCity: self::castNullableString($row, 'shipping_city'),
            shippingProvince: self::castNullableString($row, 'shipping_province'),
            shippingCountry: self::castString($row, 'shipping_country', 'IT'),
            notes: self::castNullableString($row, 'notes'),
            adminNotes: self::castNullableString($row, 'admin_notes'),
            subtotal: self::castFloat($row, 'subtotal'),
            shippingCost: self::castFloat($row, 'shipping_cost'),
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

    public function isPickup(): bool
    {
        return $this->shippingMethod === 'pickup';
    }

    public function shippingLine(): string
    {
        if ($this->isPickup()) {
            return 'Ritiro in sede';
        }

        $parts = array_filter([
            $this->shippingAddress,
            trim(($this->shippingPostalCode ?? '') . ' ' . ($this->shippingCity ?? '')),
            $this->shippingProvince !== null ? '(' . $this->shippingProvince . ')' : null,
        ]);

        return implode(', ', array_map('trim', $parts));
    }

    public static function statusLabelFor(string $status): string
    {
        return match ($status) {
            self::STATUS_NEW => 'Nuovo',
            self::STATUS_CONTACTED => 'Cliente contattato',
            self::STATUS_WAITING_PAYMENT => 'In attesa di pagamento',
            self::STATUS_PAID_OFFLINE => 'Pagato',
            self::STATUS_PREPARING => 'In preparazione',
            self::STATUS_SHIPPED => 'Spedito',
            self::STATUS_COMPLETED => 'Completato',
            self::STATUS_CANCELLED => 'Annullato',
            default => $status,
        };
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }

    /** Classi del badge di stato. Mappa esplicita: Tailwind non genera classi dinamiche. */
    public function statusClasses(): string
    {
        return match ($this->status) {
            self::STATUS_NEW => 'bg-rosso-100 text-rosso-800 ring-rosso-200',
            self::STATUS_CONTACTED, self::STATUS_WAITING_PAYMENT => 'bg-amber-100 text-amber-900 ring-amber-200',
            self::STATUS_PAID_OFFLINE, self::STATUS_PREPARING => 'bg-sky-100 text-sky-900 ring-sky-200',
            self::STATUS_SHIPPED => 'bg-viola-100 text-viola-800 ring-viola-200',
            self::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-900 ring-emerald-200',
            self::STATUS_CANCELLED => 'bg-sabbia-200 text-sabbia-800 ring-sabbia-300',
            default => 'bg-sabbia-100 text-sabbia-800 ring-sabbia-200',
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** @return list<string> */
    public static function allStatuses(): array
    {
        return [...self::STATUS_FLOW, self::STATUS_CANCELLED];
    }
}
