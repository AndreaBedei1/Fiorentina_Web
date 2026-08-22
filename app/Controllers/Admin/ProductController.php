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

        /*
         * Le fotografie si caricano prima di creare il prodotto.
         *
         * Un prodotto senza fotografia non e un prodotto: in negozio sarebbe
         * uno scaffale con un cartellino e niente sopra. E siccome un
         * caricamento puo fallire anche dopo che il file e stato scelto (un
         * .exe rinominato .jpg, un file troppo grande), non basta guardare
         * cosa ha scelto l'amministratore: bisogna guardare cosa e arrivato
         * davvero a destinazione. Se non e arrivato niente, il prodotto non
         * nasce - meglio un modulo da ricompilare che una riga a catalogo da
         * andare a correggere.
         */
        $caricate = $this->caricaImmagini($request);

        if ($caricate === []) {
            return $this->mancaLaFotografia($request, $this->url->route('admin.products.create'));
        }

        $id = $this->products->create($this->buildData($request, $validated) + [
            'created_by' => $this->currentUser()->id,
        ]);

        $this->products->replaceVariants($id, $this->parseVariants($request));
        $this->attaccaImmagini($id, $caricate);

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

        $caricate = $this->caricaImmagini($request);

        /*
         * Rete di sicurezza per i prodotti nati prima di questa regola: se uno
         * si ritrovasse senza fotografie, la modifica non si chiude finche non
         * gliene si da una.
         */
        if ($caricate === [] && $this->products->countImages($id) === 0) {
            return $this->mancaLaFotografia($request, $this->url->route('admin.products.edit', ['id' => $id]));
        }

        $this->products->update($id, $data);
        $this->products->replaceVariants($id, $this->parseVariants($request));
        $this->attaccaImmagini($id, $caricate);

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

        $this->success('Prodotto eliminato con le sue fotografie. Gli ordini già ricevuti restano invariati.');

        return $this->redirectToRoute('admin.products.index');
    }

    public function destroyImage(Request $request): Response
    {
        $this->authorize('products.manage');

        $productId = $request->routeInt('id');
        $imageId = $request->routeInt('imageId');

        /*
         * L'ultima fotografia non si elimina.
         *
         * Il pannello nasconde gia il pulsante quando ne resta una sola, ma il
         * pulsante non e la regola: la regola sta qui, dove arriva anche chi
         * preme due volte prima che la pagina si ricarichi, o chi si scrive
         * l'indirizzo a mano.
         */
        if ($this->products->countImages($productId) <= 1) {
            $this->error('Questa e l\'unica fotografia del prodotto: caricane un\'altra prima di toglierla.');

            return $this->redirectToRoute('admin.products.edit', ['id' => $productId]);
        }

        $key = $this->products->deleteImage($productId, $imageId);

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

    /**
     * Porta i file su disco e riferisce quali ce l'hanno fatta.
     *
     * Separata dall'aggancio a database perche i due momenti servono in ordini
     * diversi: creando un prodotto bisogna sapere se almeno una fotografia e
     * arrivata prima di scrivere la riga, modificandolo il prodotto c'e gia.
     *
     * @return list<array{key: string, extension: string}>
     */
    private function caricaImmagini(Request $request): array
    {
        $riuscite = [];

        foreach ($request->fileList('images') as $file) {
            $esito = $this->images->store($file, MediaPaths::COLLECTION_PRODUCTS);

            if ($esito['error'] !== null) {
                $this->warning($esito['error']);

                continue;
            }

            $riuscite[] = [
                'key' => (string) $esito['key'],
                'extension' => (string) $esito['extension'],
            ];
        }

        return $riuscite;
    }

    /**
     * Aggancia al prodotto le fotografie gia salvate su disco.
     *
     * @param list<array{key: string, extension: string}> $immagini
     */
    private function attaccaImmagini(int $productId, array $immagini): void
    {
        if ($immagini === []) {
            return;
        }

        $posizione = $this->products->countImages($productId);
        $haPrincipale = $posizione > 0;

        foreach ($immagini as $immagine) {
            $this->products->addImage([
                'product_id' => $productId,
                'storage_key' => $immagine['key'],
                'extension' => $immagine['extension'],
                'sort_order' => $posizione++,
                // La prima immagine caricata diventa automaticamente la principale.
                'is_primary' => $haPrincipale ? 0 : 1,
            ]);

            $haPrincipale = true;
        }
    }

    /** Il modulo torna indietro con il campo delle immagini segnalato. */
    private function mancaLaFotografia(Request $request, string $ritorno): Response
    {
        $this->session->flashInput($request->all());
        $this->session->flashErrors(['images' => 'Serve almeno una fotografia del prodotto.']);
        $this->error('Il prodotto ha bisogno di almeno una fotografia.');

        return $this->back($request, $ritorno);
    }
}
