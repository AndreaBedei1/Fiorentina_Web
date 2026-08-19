<?php

declare(strict_types=1);

return [
    'currency' => 'EUR',
    'currency_symbol' => 'euro',

    // Prefisso dei numeri d'ordine: BF-2026-000001
    'order_prefix' => 'BF',

    'shipping' => [
        // Costo base di spedizione, sovrascrivibile dal pannello Impostazioni.
        'flat_rate' => 7.00,
        // Sopra questa soglia la spedizione e gratuita (0 = disattivata).
        'free_threshold' => 80.00,
        // Ritiro in sede: alternativa senza costi, molto usata dai soci.
        'pickup_enabled' => true,
        'pickup_label' => 'Ritiro in sede',
    ],

    'cart' => [
        'max_items' => 30,
        'max_quantity_per_item' => 20,
        'session_key' => 'cart',
    ],
];
