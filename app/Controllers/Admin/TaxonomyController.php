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
use App\Repositories\EventCategoryRepository;
use App\Repositories\ProductCategoryRepository;
use App\Services\AuthService;
use App\Validation\Validator;

/**
 * Categorie di eventi e prodotti.
 *
 * Due elenchi brevi e simili: tenerli in un unico controller evita due classi
 * quasi identiche, e per l amministratore restano due schede della stessa
 * pagina.
 */
final class TaxonomyController extends Controller
{
    /** Icone disponibili per le categorie evento: chiavi, non emoji. */
    private const ICONS = [
        'calendar' => 'Calendario',
        'bus' => 'Trasferta',
        'users' => 'Riunione',
        'dinner' => 'Cena',
        'party' => 'Festa',
        'flag' => 'Raduno',
        'star' => 'Evento speciale',
        'ball' => 'Partita',
    ];

    private const COLORS = [
        'viola' => 'Viola',
        'rosso' => 'Rosso',
        'ambra' => 'Ambra',
        'verde' => 'Verde',
        'blu' => 'Blu',
        'sabbia' => 'Neutro',
    ];

    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly EventCategoryRepository $eventCategories,
        private readonly ProductCategoryRepository $productCategories,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('events.manage');

        return $this->render('admin/taxonomy.twig', [
            'seo' => $this->seo('Categorie')->withNoindex(),
            'eventCategories' => $this->eventCategories->all(),
            'productCategories' => $this->productCategories->all(),
            'icons' => self::ICONS,
            'colors' => self::COLORS,
        ]);
    }

    // --- Categorie evento -------------------------------------------------

    public function storeEventCategory(Request $request): Response
    {
        $this->authorize('events.manage');

        $data = $this->validateCategory($request, withIcon: true);

        if ($data === null) {
            return $this->redirectToRoute('admin.taxonomy.index');
        }

        $this->eventCategories->create([
            'name' => (string) $data['name'],
            'slug' => Str::slug((string) $data['name']),
            'description' => $data['description'] ?? null,
            'icon' => (string) ($data['icon'] ?? 'calendar'),
            'color' => (string) ($data['color'] ?? 'viola'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->success('Categoria evento creata.');

        return $this->redirectToRoute('admin.taxonomy.index');
    }

    public function updateEventCategory(Request $request): Response
    {
        $this->authorize('events.manage');

        $id = $request->routeInt('id');

        if ($this->eventCategories->find($id) === null) {
            $this->notFound('Categoria non trovata.');
        }

        $this->eventCategories->update($id, [
            'name' => $request->string('name'),
            'description' => $request->nullableString('description'),
            'icon' => array_key_exists($request->string('icon'), self::ICONS) ? $request->string('icon') : 'calendar',
            'color' => array_key_exists($request->string('color'), self::COLORS) ? $request->string('color') : 'viola',
            'sort_order' => $request->int('sort_order'),
        ]);

        $this->success('Categoria aggiornata.');

        return $this->redirectToRoute('admin.taxonomy.index');
    }

    public function destroyEventCategory(Request $request): Response
    {
        $this->authorize('events.manage');

        // Gli eventi collegati restano pubblicati, semplicemente senza categoria.
        $this->eventCategories->delete($request->routeInt('id'));
        $this->success('Categoria eliminata. Gli eventi collegati restano pubblicati.');

        return $this->redirectToRoute('admin.taxonomy.index');
    }

    // --- Categorie prodotto -----------------------------------------------

    public function storeProductCategory(Request $request): Response
    {
        $this->authorize('products.manage');

        $data = $this->validateCategory($request, withIcon: false);

        if ($data === null) {
            return $this->redirectToRoute('admin.taxonomy.index');
        }

        $this->productCategories->create([
            'name' => (string) $data['name'],
            'slug' => Str::slug((string) $data['name']),
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'status' => $request->bool('active') ? 'active' : 'hidden',
        ]);

        $this->success('Categoria prodotto creata.');

        return $this->redirectToRoute('admin.taxonomy.index');
    }

    public function updateProductCategory(Request $request): Response
    {
        $this->authorize('products.manage');

        $id = $request->routeInt('id');

        if ($this->productCategories->find($id) === null) {
            $this->notFound('Categoria non trovata.');
        }

        $this->productCategories->update($id, [
            'name' => $request->string('name'),
            'description' => $request->nullableString('description'),
            'sort_order' => $request->int('sort_order'),
            'status' => $request->bool('active') ? 'active' : 'hidden',
        ]);

        $this->success('Categoria aggiornata.');

        return $this->redirectToRoute('admin.taxonomy.index');
    }

    public function destroyProductCategory(Request $request): Response
    {
        $this->authorize('products.manage');

        $this->productCategories->delete($request->routeInt('id'));
        $this->success('Categoria eliminata. I prodotti collegati restano in catalogo.');

        return $this->redirectToRoute('admin.taxonomy.index');
    }

    /** @return array<string, mixed>|null */
    private function validateCategory(Request $request, bool $withIcon): ?array
    {
        $validator = Validator::make($request->all())
            ->required('name', 'Il nome')->max('name', 100, 'Il nome')
            ->optional('description')->max('description', 300, 'La descrizione')
            ->integer('sort_order', 'L ordinamento', 0, 999);

        if ($withIcon) {
            $validator
                ->in('icon', array_keys(self::ICONS), 'L icona')
                ->in('color', array_keys(self::COLORS), 'Il colore');
        }

        if ($validator->fails()) {
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return null;
        }

        return $validator->validatedData();
    }
}
