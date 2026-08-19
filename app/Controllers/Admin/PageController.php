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
use App\Models\PageBlock;
use App\Repositories\PageRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\HtmlSanitizer;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Validation\Validator;

/**
 * Modifica delle pagine editoriali (Chi siamo, Diventa socio, Contatti,
 * Privacy, Cookie policy) e dei loro blocchi.
 *
 * I blocchi hanno tipi fissi e campi chiari: non e un costruttore di pagine
 * libero, ed e una scelta precisa. Un editor generico permetterebbe di
 * scomporre il layout, e chi eredita il sito si troverebbe a doverlo rimettere
 * a posto.
 */
final class PageController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly PageRepository $pages,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SimpleImageService $images,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('pages.manage');

        return $this->render('admin/pages/index.twig', [
            'seo' => $this->seo('Pagine')->withNoindex(),
            'pages' => $this->pages->all(),
        ]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('pages.manage');

        $page = $this->pages->find($request->routeInt('id'));

        if ($page === null) {
            $this->notFound('Pagina non trovata.');
        }

        return $this->render('admin/pages/form.twig', [
            'seo' => $this->seo('Modifica pagina')->withNoindex(),
            'page' => $page,
            'blockTypes' => PageBlock::allTypes(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('pages.manage');

        $id = $request->routeInt('id');
        $page = $this->pages->find($id, withBlocks: false);

        if ($page === null) {
            $this->notFound('Pagina non trovata.');
        }

        $validator = Validator::make($request->all())
            ->required('title', 'Il titolo')->max('title', 200, 'Il titolo')
            ->optional('subtitle')->max('subtitle', 300, 'Il sottotitolo')
            ->optional('intro')->max('intro', 2000, 'L introduzione')
            ->optional('meta_title')->max('meta_title', 200, 'Il titolo SEO')
            ->optional('meta_description')->max('meta_description', 300, 'La descrizione SEO')
            ->in('status', ['draft', 'published'], 'Lo stato');

        if ($validator->fails()) {
            return $this->backWithErrors(
                $request,
                $request->all(),
                $validator->errors(),
                $this->url->route('admin.pages.edit', ['id' => $id]),
            );
        }

        $validated = $validator->validatedData();

        $data = [
            'title' => (string) $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'intro' => $validated['intro'] ?? null,
            'content' => $this->sanitizer->clean((string) $request->post('content', '')),
            'hero_image_alt' => $request->nullableString('hero_image_alt'),
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'status' => (string) ($validated['status'] ?? 'published'),
            'updated_by' => $this->currentUser()->id,
        ];

        if ($request->hasFile('hero_image')) {
            $file = $request->file('hero_image');

            if ($file !== null) {
                $result = $this->images->store($file, MediaPaths::COLLECTION_PAGES, $page->heroImageKey);

                if ($result['error'] !== null) {
                    $this->error($result['error']);
                } else {
                    $data['hero_image_key'] = $result['key'];
                }
            }
        }

        if ($request->bool('remove_hero_image') && $page->heroImageKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_PAGES, $page->heroImageKey);
            $data['hero_image_key'] = null;
        }

        $this->pages->update($id, $data);

        $this->audit->log(
            AuditLogger::CONTENT_UPDATED,
            'page',
            $id,
            sprintf('Pagina aggiornata: %s', $data['title']),
        );

        $this->success('Pagina aggiornata.');

        return $this->redirectToRoute('admin.pages.edit', ['id' => $id]);
    }

    // -----------------------------------------------------------------------
    //  Blocchi
    // -----------------------------------------------------------------------

    public function storeBlock(Request $request): Response
    {
        $this->authorize('pages.manage');

        $pageId = $request->routeInt('id');

        if ($this->pages->find($pageId, withBlocks: false) === null) {
            $this->notFound('Pagina non trovata.');
        }

        $type = $request->string('type');

        if (! array_key_exists($type, PageBlock::allTypes())) {
            $this->error('Tipo di blocco non valido.');

            return $this->redirectToRoute('admin.pages.edit', ['id' => $pageId]);
        }

        $this->pages->createBlock($pageId, $this->blockData($request, $type));
        $this->success('Blocco aggiunto.');

        return $this->redirectToRoute('admin.pages.edit', ['id' => $pageId]);
    }

    public function updateBlock(Request $request): Response
    {
        $this->authorize('pages.manage');

        $pageId = $request->routeInt('id');
        $blockId = $request->routeInt('blockId');

        $block = $this->pages->findBlock($blockId);

        if ($block === null || $block->pageId !== $pageId) {
            $this->notFound('Blocco non trovato.');
        }

        $this->pages->updateBlock($blockId, $this->blockData($request, $block->type));
        $this->success('Blocco aggiornato.');

        return $this->redirectToRoute('admin.pages.edit', ['id' => $pageId]);
    }

    public function destroyBlock(Request $request): Response
    {
        $this->authorize('pages.manage');

        $pageId = $request->routeInt('id');
        $blockId = $request->routeInt('blockId');

        $block = $this->pages->findBlock($blockId);

        if ($block !== null && $block->pageId === $pageId) {
            $this->pages->deleteBlock($blockId);
            $this->success('Blocco eliminato.');
        }

        return $this->redirectToRoute('admin.pages.edit', ['id' => $pageId]);
    }

    public function reorderBlocks(Request $request): Response
    {
        $this->authorize('pages.manage');

        $pageId = $request->routeInt('id');
        $order = $request->intList('order');

        if ($order === []) {
            return $this->json(['ok' => false, 'message' => 'Ordine non valido.'], 422);
        }

        $this->pages->reorderBlocks($pageId, $order);

        return $this->json(['ok' => true, 'message' => 'Ordine salvato.']);
    }

    /**
     * Dati di un blocco.
     *
     * Gli elenchi vengono scritti una voce per riga: e il modo piu naturale per
     * chi non e tecnico, e la conversione in struttura avviene qui.
     *
     * @return array<string, mixed>
     */
    private function blockData(Request $request, string $type): array
    {
        $items = null;

        if (in_array($type, [PageBlock::TYPE_LIST, PageBlock::TYPE_STEPS, PageBlock::TYPE_STATS, PageBlock::TYPE_FAQ, PageBlock::TYPE_TIMELINE], true)) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $request->post('items_text', '')) ?: [];
            $parsed = [];

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                // "Titolo | descrizione" separa le due parti; senza barra
                // verticale la riga e solo un titolo.
                $parts = array_map('trim', explode('|', $line, 2));

                $parsed[] = [
                    'title' => mb_substr($parts[0], 0, 200),
                    'text' => isset($parts[1]) ? mb_substr($parts[1], 0, 600) : '',
                ];
            }

            $items = $parsed === [] ? null : json_encode($parsed, JSON_UNESCAPED_UNICODE);
        }

        return [
            'type' => $type,
            'title' => $request->nullableString('title'),
            'subtitle' => $request->nullableString('subtitle'),
            'body' => $this->sanitizer->clean((string) $request->post('body', '')),
            'items' => $items,
            'icon' => $request->nullableString('icon'),
            'link_url' => $request->nullableString('link_url'),
            'link_label' => $request->nullableString('link_label'),
            'is_visible' => $request->bool('is_visible') ? 1 : 0,
        ];
    }
}
