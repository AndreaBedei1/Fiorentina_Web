<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\Support\Str;
use App\Core\View\ViewRenderer;
use App\Models\Product;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\HtmlSanitizer;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Validation\Validator;

/** Gestione del catalogo merchandising. */
final class ProductController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $categories,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SimpleImageService $images,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('products.manage');

        $status = $request->string('stato');

        return $this->render('admin/products/index.twig', [
            'seo' => $this->seo('Prodotti')->withNoindex(),
            'paginator' => $this->products->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                status: $status !== '' ? $status : null,
                search: $request->string('q'),
                basePath: $this->url->route('admin.products.index'),
            ),
            'activeStatus' => $status,
            'search' => $request->string('q'),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('products.manage');

        return $this->render('admin/products/form.twig', [
            'seo' => $this->seo('Nuovo prodotto')->withNoindex(),
            'product' => null,
            'categories' => $this->categories->all(),
            'statuses' => $this->statusOptions(),
            'availabilities' => $this->availabilityOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('products.manage');

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.products.create'));
        }

        $id = $this->products->create($this->buildData($request, $validated) + [
            'created_by' => $this->currentUser()->id,
        ]);

        $this->products->replaceVariants($id, $this->parseVariants($request));
        $this->storeImages($request, $id);

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'product',
            $id,
            sprintf('Prodotto creato: %s', $validated['name']),
            ['price' => $validated['price'] ?? 0],
        );

        $this->success('Prodotto creato.');

        return $this->redirectToRoute('admin.products.edit', ['id' => $id]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('products.manage');

        $product = $this->products->find($request->routeInt('id'));

        if ($product === null) {
            $this->notFound('Prodotto non trovato.');
        }

        return $this->render('admin/products/form.twig', [
            'seo' => $this->seo('Modifica prodotto')->withNoindex(),
            'product' => $product,
            'categories' => $this->categories->all(),
            'statuses' => $this->statusOptions(),
            'availabilities' => $this->availabilityOptions(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('products.manage');

        $id = $request->routeInt('id');
        $product = $this->products->find($id);

        if ($product === null) {
            $this->notFound('Prodotto non trovato.');
        }

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.products.edit', ['id' => $id]));
        }

        $data = $this->buildData($request, $validated);

        if (! $request->bool('update_slug')) {
            unset($data['slug']);
        }

        $this->products->update($id, $data);
        $this->products->replaceVariants($id, $this->parseVariants($request));
        $this->storeImages($request, $id);

        $this->audit->log(
            AuditLogger::CONTENT_UPDATED,
            'product',
            $id,
            sprintf('Prodotto aggiornato: %s', $validated['name']),
            ['price' => $validated['price'] ?? 0, 'status' => $data['status']],
        );

        $this->success('Prodotto aggiornato.');

        return $this->redirectToRoute('admin.products.edit', ['id' => $id]);
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('products.manage');

        $id = $request->routeInt('id');
        $product = $this->products->find($id);

        if ($product === null) {
            $this->notFound('Prodotto non trovato.');
        }

        // Soft delete: gli ordini già registrati continuano a puntare al
        // prodotto, e il loro storico resta consultabile.
        $this->products->delete($id);

        $this->audit->log(
            AuditLogger::CONTENT_DELETED,
            'product',
            $id,
            sprintf('Prodotto eliminato: %s', $product->name),
        );

        $this->success('Prodotto eliminato. Gli ordini già ricevuti restano invariati.');

        return $this->redirectToRoute('admin.products.index');
    }

    public function destroyImage(Request $request): Response
    {
        $this->authorize('products.manage');

        $productId = $request->routeInt('id');
        $imageId = $request->routeInt('imageId');

        $key = $this->products->deleteImage($imageId);

        if ($key !== null) {
            $this->images->delete(MediaPaths::COLLECTION_PRODUCTS, $key);
            $this->success('Immagine eliminata.');
        }

        return $this->redirectToRoute('admin.products.edit', ['id' => $productId]);
    }

    public function setPrimaryImage(Request $request): Response
    {
        $this->authorize('products.manage');

        $productId = $request->routeInt('id');
        $this->products->setPrimaryImage($productId, $request->routeInt('imageId'));
        $this->success('Immagine principale aggiornata.');

        return $this->redirectToRoute('admin.products.edit', ['id' => $productId]);
    }

    // -----------------------------------------------------------------------
    //  Supporto
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function validateInput(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('name', 'Il nome')->max('name', 200, 'Il nome')
            ->optional('short_description')->max('short_description', 300, 'La descrizione breve')
            ->decimal('price', 'Il prezzo', 0, 100000)
            ->decimal('compare_at_price', 'Il prezzo di listino', 0, 100000)
            ->integer('quantity', 'La quantita', 0, 100000)
            ->integer('sort_order', 'L ordinamento', 0, 9999)
            ->in('status', array_keys($this->statusOptions()), 'Lo stato')
            ->in('availability', array_keys($this->availabilityOptions()), 'La disponibilita')
            ->optional('meta_title')->max('meta_title', 200, 'Il titolo SEO')
            ->optional('meta_description')->max('meta_description', 300, 'La descrizione SEO');

        if ($request->filled('price') === false) {
            $validator->addError('price', 'Il prezzo è obbligatorio.');
        }

        $categoryIds = array_map('strval', array_keys($this->categories->options()));

        if ($categoryIds !== []) {
            $validator->in('category_id', $categoryIds, 'La categoria');
        }

        if ($validator->fails()) {
            $this->session->flashInput($request->all());
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return null;
        }

        return $validator->validatedData();
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function buildData(Request $request, array $validated): array
    {
        $trackQuantity = $request->bool('track_quantity');

        return [
            'category_id' => $request->nullableInt('category_id'),
            'name' => (string) $validated['name'],
            'slug' => Str::slug($request->string('slug') !== '' ? $request->string('slug') : (string) $validated['name']),
            'short_description' => $validated['short_description'] ?? null,
            'description' => $this->sanitizer->clean((string) $request->post('description', '')),
            'price' => (float) ($validated['price'] ?? 0),
            'compare_at_price' => $validated['compare_at_price'] ?? null,
            'availability' => (string) ($validated['availability'] ?? Product::AVAILABILITY_IN_STOCK),
            'track_quantity' => $trackQuantity ? 1 : 0,
            'quantity' => $trackQuantity ? ($validated['quantity'] ?? 0) : null,
            'is_featured' => $request->bool('is_featured') ? 1 : 0,
            'status' => (string) ($validated['status'] ?? Product::STATUS_DRAFT),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ];
    }

    /**
     * Legge le varianti dal form: righe parallele di campi con lo stesso indice.
     *
     * @return list<array<string, mixed>>
     */
    private function parseVariants(Request $request): array
    {
        $labels = $request->array('variant_label');
        $variants = [];

        foreach ($labels as $index => $label) {
            $label = trim((string) $label);

            if ($label === '') {
                continue;
            }

            $variants[] = [
                'label' => mb_substr($label, 0, 80),
                'size' => $this->variantField($request, 'variant_size', $index),
                'color' => $this->variantField($request, 'variant_color', $index),
                'sku' => $this->variantField($request, 'variant_sku', $index),
                'price_modifier' => (float) str_replace(',', '.', (string) ($request->array('variant_price_modifier')[$index] ?? '0')),
                'quantity' => is_numeric($request->array('variant_quantity')[$index] ?? null)
                    ? (int) $request->array('variant_quantity')[$index]
                    : null,
                'is_available' => ! empty($request->array('variant_available')[$index]),
            ];
        }

        return $variants;
    }

    private function variantField(Request $request, string $field, int|string $index): ?string
    {
        $value = trim((string) ($request->array($field)[$index] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 60);
    }

    private function storeImages(Request $request, int $productId): void
    {
        $files = $request->fileList('images');

        if ($files === []) {
            return;
        }

        $hasPrimary = $this->products->imageKeysFor($productId) !== [];
        $position = count($this->products->imageKeysFor($productId));

        foreach ($files as $file) {
            $result = $this->images->store($file, MediaPaths::COLLECTION_PRODUCTS);

            if ($result['error'] !== null) {
                $this->warning($result['error']);

                continue;
            }

            $this->products->addImage([
                'product_id' => $productId,
                'storage_key' => (string) $result['key'],
                'extension' => (string) $result['extension'],
                'alt_text' => $request->nullableString('image_alt'),
                'sort_order' => $position++,
                // La prima immagine caricata diventa automaticamente la principale.
                'is_primary' => $hasPrimary ? 0 : 1,
            ]);

            $hasPrimary = true;
        }
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            Product::STATUS_DRAFT => 'Bozza',
            Product::STATUS_PUBLISHED => 'Pubblicato',
            Product::STATUS_ARCHIVED => 'Archiviato',
        ];
    }

    /** @return array<string, string> */
    private function availabilityOptions(): array
    {
        return [
            Product::AVAILABILITY_IN_STOCK => 'Disponibile',
            Product::AVAILABILITY_OUT_OF_STOCK => 'Esaurito',
            Product::AVAILABILITY_PREORDER => 'Su prenotazione',
            Product::AVAILABILITY_DISCONTINUED => 'Non più disponibile',
        ];
    }
}
