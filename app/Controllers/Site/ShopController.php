<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Models\Product;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;

/**
 * Catalogo merchandising.
 *
 * Nota importante sui dati strutturati: dichiariamo prezzo e disponibilità, ma
 * non un metodo di pagamento, perché il sito non ne ha. Un ordine e una
 * richiesta che si perfeziona offline, e i dati strutturati lo rispecchiano.
 */
final class ShopController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $categories,
        private readonly SettingsService $settings,
        private readonly MediaPaths $media,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $categorySlug = $request->string('categoria');
        $categorySlug = $categorySlug !== '' ? $categorySlug : null;
        $search = $request->string('q');
        $sort = $request->string('ordina');

        $paginator = $this->products->paginatePublished(
            page: $page,
            perPage: 12,
            categorySlug: $categorySlug,
            search: $search,
            sort: $sort !== '' ? $sort : null,
            basePath: $this->url->route('shop.index'),
        );

        $seo = $this->seo(
            'Merchandising',
            'Sciarpe, magliette, felpe, cappellini e gadget ufficiali di ' . $this->settings->string('site_group_name') . '.',
        )
            ->withCanonical($this->url->absoluteRoute('shop.index'))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Merchandising', 'url' => $this->url->route('shop.index')],
            ]);

        if ($page > 1 || $categorySlug !== null || $search !== '' || $sort !== '') {
            $seo->withNoindex();
        }

        return $this->render('site/shop/index.twig', [
            'seo' => $seo,
            'paginator' => $paginator,
            'categories' => $this->categories->activeWithProducts(),
            'activeCategory' => $categorySlug,
            'search' => $search,
            'sort' => $sort,
            'shopEnabled' => $this->settings->bool('shop_enabled', true),
        ]);
    }

    public function show(Request $request): Response
    {
        // Conta il numero davanti; il nome che segue rende leggibile il
        // collegamento e puo cambiare senza spezzare niente.
        $riferimento = (string) $request->route('riferimento');
        $product = $this->products->findPublished((int) $riferimento);

        if ($product === null) {
            $this->notFound('Il prodotto che cerchi non e disponibile.');
        }

        if ($riferimento !== $product->urlKey()) {
            return RedirectResponse::to($this->url->route('shop.show', ['riferimento' => $product->urlKey()]), 301);
        }

        $imageUrl = $product->primaryImage() !== null
            ? $this->url->absolute($this->media->url(
                MediaPaths::COLLECTION_PRODUCTS,
                $product->primaryImage()->storageKey,
                MediaPaths::SIZE_LARGE,
                'jpg',
            ))
            : null;

        $seo = $this->seo($product->seoTitle(), $product->seoDescription())
            ->withType('product')
            ->withImage($imageUrl)
            ->withCanonical($this->url->absoluteRoute('shop.show', ['riferimento' => $product->urlKey()]))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Merchandising', 'url' => $this->url->route('shop.index')],
                ['name' => $product->name, 'url' => $this->url->route('shop.show', ['riferimento' => $product->urlKey()])],
            ])
            ->withStructuredData($this->productSchema($product, $imageUrl));

        return $this->render('site/shop/show.twig', [
            'seo' => $seo,
            'product' => $product,
            'shopEnabled' => $this->settings->bool('shop_enabled', true),
        ]);
    }

    /** @return array<string, mixed> */
    private function productSchema(Product $product, ?string $imageUrl): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $product->summary(200),
            'brand' => [
                '@type' => 'Brand',
                'name' => $this->settings->string('site_group_name'),
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format($product->price, 2, '.', ''),
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => $this->url->absoluteRoute('shop.show', ['riferimento' => $product->urlKey()]),
            ],
        ];

        if ($imageUrl !== null) {
            $schema['image'] = [$imageUrl];
        }

        if ($product->categoryName !== null) {
            $schema['category'] = $product->categoryName;
        }

        return $schema;
    }
}
