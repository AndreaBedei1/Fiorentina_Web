<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\Config;
use App\Services\SettingsService;

/**
 * Calcolo delle spese di spedizione.
 *
 * Regole volutamente semplici e configurabili dal pannello: tariffa unica,
 * soglia di gratuita, ritiro in sede senza costi. Il gruppo spedisce poche
 * decine di pacchi l'anno, quasi tutti in Italia: una tabella per fasce di peso
 * o per zona sarebbe complessita senza ritorno.
 */
final class ShippingCalculator
{
    public const METHOD_DELIVERY = 'delivery';
    public const METHOD_PICKUP = 'pickup';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    public function costFor(float $subtotal, string $method): float
    {
        if ($method === self::METHOD_PICKUP) {
            return 0.0;
        }

        if ($subtotal <= 0.0) {
            return 0.0;
        }

        $threshold = $this->freeThreshold();

        if ($threshold > 0.0 && $subtotal >= $threshold) {
            return 0.0;
        }

        return round($this->flatRate(), 2);
    }

    public function flatRate(): float
    {
        return $this->settings->float('shop_shipping_cost', $this->config->get('shop.shipping.flat_rate', 7.0));
    }

    public function freeThreshold(): float
    {
        return $this->settings->float(
            'shop_free_shipping_threshold',
            $this->config->get('shop.shipping.free_threshold', 80.0),
        );
    }

    public function pickupEnabled(): bool
    {
        return $this->settings->bool('shop_pickup_enabled', $this->config->get('shop.shipping.pickup_enabled', true));
    }

    /** Quanto manca alla spedizione gratuita: informazione utile in carrello. */
    public function amountToFreeShipping(float $subtotal): ?float
    {
        $threshold = $this->freeThreshold();

        if ($threshold <= 0.0 || $subtotal >= $threshold) {
            return null;
        }

        return round($threshold - $subtotal, 2);
    }

    /** @return array<string, string> Metodi di consegna disponibili. */
    public function availableMethods(): array
    {
        $methods = [self::METHOD_DELIVERY => 'Spedizione a domicilio'];

        if ($this->pickupEnabled()) {
            $methods[self::METHOD_PICKUP] = 'Ritiro in sede (nessun costo)';
        }

        return $methods;
    }

    public function isValidMethod(string $method): bool
    {
        return array_key_exists($method, $this->availableMethods());
    }
}
