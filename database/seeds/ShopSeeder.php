<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Repositories\ProductRepository;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;

/** Catalogo merchandising dimostrativo, con varianti e immagini. */
final class ShopSeeder extends Seeder
{
    private const PRODUCTS = [
        [
            'name' => 'Sciarpa ufficiale Baraonda',
            'category' => 'sciarpe',
            'short' => 'Sciarpa in acrilico, doppia faccia, con il nome del gruppo su fondo viola.',
            'description' => '<p>La sciarpa storica del gruppo: acrilico pesante, doppia faccia, lunghezza 140 cm.</p><p>Prodotta in serie limitata a ogni stagione.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 18.00,
            'taglie' => [],
        ],
        [
            'name' => 'Maglietta Curva Fiesole',
            'category' => 'magliette',
            'short' => 'T-shirt in cotone pesante con stampa serigrafica sul fronte.',
            'description' => '<p>Cotone 100% da 190 grammi, stampa serigrafica resistente al lavaggio.</p><p>Vestibilità regolare: chi la preferisce più larga può prendere una taglia in più.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 22.00,
            'taglie' => ['S', 'M', 'L', 'XL', 'XXL'],
        ],
        [
            'name' => 'Felpa con cappuccio',
            'category' => 'felpe',
            'short' => 'Felpa pesante con cappuccio e tasca a marsupio, ricamo sul petto.',
            'description' => '<p>Felpa invernale in cotone garzato, 320 grammi. Ricamo sul petto e stampa sul retro.</p><p>La più richiesta per le trasferte invernali.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 45.00,
            'taglie' => ['S', 'M', 'L', 'XL', 'XXL'],
        ],
        [
            'name' => 'Cappellino con visiera',
            'category' => 'cappellini',
            'short' => 'Cappellino regolabile con logo ricamato.',
            'description' => '<p>Taglia unica regolabile con fibbia posteriore. Logo ricamato sul fronte.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 15.00,
            'taglie' => [],
        ],
        [
            'name' => 'Set adesivi (10 pezzi)',
            'category' => 'gadget',
            'short' => 'Dieci adesivi resistenti agli agenti atmosferici, formati misti.',
            'description' => '<p>Adesivi in vinile resistente, adatti anche all\'esterno. Formati misti, dieci pezzi per confezione.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 5.00,
            'taglie' => [],
        ],
        [
            'name' => 'Portachiavi in metallo',
            'category' => 'gadget',
            'short' => 'Portachiavi smaltato con il simbolo del gruppo.',
            'description' => '<p>Metallo smaltato, diametro 35 mm.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 7.00,
            'taglie' => [],
        ],
        [
            'name' => 'Bandiera da asta',
            'category' => 'gadget',
            'short' => 'Bandiera 100x140 cm in poliestere, con asola per l\'asta.',
            'description' => '<p>Poliestere resistente, stampa su entrambi i lati.</p><p><em>Descrizione dimostrativa.</em></p>',
            'price' => 25.00,
            'taglie' => [],
        ],
        [
            'name' => 'Maglietta celebrativa anniversario',
            'category' => 'magliette',
            'short' => 'Edizione limitata per l\'anniversario del gruppo. Esaurita.',
            'description' => '<p>Prodotta in tiratura limitata. Al momento non è disponibile.</p><p><em>Descrizione dimostrativa: mostra come appare un articolo esaurito.</em></p>',
            'price' => 25.00,
            'taglie' => [],
        ],
    ];

    public function name(): string
    {
        return 'Catalogo merchandising';
    }

    public function run(): int
    {
        if ($this->tableHasRows('products')) {
            $this->say('Prodotti già presenti: salto.');

            return 0;
        }

        $products = $this->app->get(ProductRepository::class);
        $categories = $this->db->pairs('SELECT slug, id FROM product_categories');
        $authorId = $this->db->scalar("SELECT id FROM users WHERE role = 'SUPER_ADMIN' ORDER BY id LIMIT 1");

        $withImages = DemoImageFactory::isAvailable();
        $factory = $withImages ? new DemoImageFactory($this->app->storagePath('temp')) : null;
        $images = $withImages ? $this->app->get(SimpleImageService::class) : null;

        $created = 0;
        $seed = 100;

        foreach (self::PRODUCTS as $item) {
            $productId = $products->create([
                'category_id' => isset($categories[$item['category']]) ? (int) $categories[$item['category']] : null,
                'name' => $item['name'],
                'short_description' => $item['short'],
                'description' => $item['description'],
                'price' => $item['price'],
                'created_by' => $authorId === null ? null : (int) $authorId,
            ]);

            if ($item['taglie'] !== []) {
                $products->replaceVariants($productId, array_map(
                    static fn (string $taglia): array => ['label' => $taglia],
                    $item['taglie'],
                ));
            }

            if ($factory !== null && $images !== null) {
                $file = $factory->create($item['name'], $seed++, 1200, 1200);
                $result = $images->store($file, MediaPaths::COLLECTION_PRODUCTS);

                if ($result['error'] === null) {
                    $products->addImage([
                        'product_id' => $productId,
                        'storage_key' => (string) $result['key'],
                        'extension' => (string) $result['extension'],
                        'sort_order' => 0,
                        'is_primary' => 1,
                    ]);
                }
            }

            $created++;
        }

        $factory?->cleanup();

        $this->say(sprintf('%d prodotti creati.', $created));

        return $created;
    }
}
