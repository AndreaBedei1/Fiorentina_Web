<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
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

        return $this->render('admin/products/index.twig', [
            'seo' => $this->seo('Prodotti')->withNoindex(),
            'paginator' => $this->products->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                basePath: $this->url->route('admin.products.index'),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('products.manage');

        return $this->render('admin/products/form.twig', [
            'seo' => $this->seo('Nuovo prodotto')->withNoindex(),
            'product' => null,
            'categoryOptions' => $this->categories->options(),
            'taglieDisponibili' => Product::TAGLIE,
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
            'categoryOptions' => $this->categories->options(),
            'taglieDisponibili' => Product::TAGLIE,
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


        $this->products->update($id, $data);
        $this->products->replaceVariants($id, $this->parseVariants($request));
        $this->storeImages($request, $id);

        $this->audit->log(
            AuditLogger::CONTENT_UPDATED,
            'product',
            $id,
            sprintf('Prodotto aggiornato: %s', $validated['name']),
            ['price' => $validated['price'] ?? 0],
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

        /*
         * Eliminazione vera, immagini comprese.
         *
         * Gli ordini gia registrati non ne soffrono: la riga d'ordine tiene
         * la propria copia di nome e prezzo, e il riferimento al prodotto
         * diventa nullo da solo (ON DELETE SET NULL). Quello che sparisce e
         * l'articolo dal catalogo, che e esattamente quello che si chiede
         * premendo "elimina".
         */
        $immagini = $this->products->fileImmaginiDi($id);

        $this->products->delete($id);

        foreach ($immagini as $immagine) {
            $this->images->delete(MediaPaths::COLLECTION_PRODUCTS, $immagine['storage_key'], $immagine['extension']);
        }

        $this->audit->log(
            AuditLogger::CONTENT_DELETED,
            'product',
            $id,
            sprintf('Prodotto eliminato: %s', $product->name),
        );

        $this->success('Prodotto eliminato con le sue fotografie. Gli ordini già ricevuti restano invariati.');

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
            ->decimal('price', 'Il prezzo', 0, 100000);

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
        return [
            'category_id' => $request->nullableInt('category_id'),
            'name' => (string) $validated['name'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $this->sanitizer->clean((string) $request->post('description', '')),
            'price' => (float) ($validated['price'] ?? 0),
        ];
    }

    /**
     * Le taglie spuntate, nell'ordine dell'elenco.
     *
     * Non si accetta quello che arriva: si parte dall'elenco chiuso e si
     * tiene cio che e stato spuntato. Cosi l'ordine e sempre XS, S, M... e
     * nessun valore inventato entra a database.
     *
     * @return list<array<string, mixed>>
     */
    private function parseVariants(Request $request): array
    {
        $scelte = array_map('strval', $request->array('taglie'));

        return array_values(array_map(
            static fn (string $taglia): array => ['label' => $taglia],
            array_filter(Product::TAGLIE, static fn (string $t): bool => in_array($t, $scelte, true)),
        ));
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


}
