<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\Support\Dates;
use App\Core\Support\Str;
use App\Core\View\ViewRenderer;
use App\Models\News;
use App\Repositories\NewsRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\HtmlSanitizer;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Validation\Validator;

/** Gestione delle notizie dall'area riservata. */
final class NewsController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly NewsRepository $news,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SimpleImageService $images,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('news.manage');

        $status = $request->string('stato');
        $search = $request->string('q');

        return $this->render('admin/news/index.twig', [
            'seo' => $this->seo('Notizie')->withNoindex(),
            'paginator' => $this->news->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                status: $status !== '' ? $status : null,
                search: $search,
                basePath: $this->url->route('admin.news.index'),
            ),
            'activeStatus' => $status,
            'search' => $search,
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('news.manage');

        return $this->render('admin/news/form.twig', [
            'seo' => $this->seo('Nuova notizia')->withNoindex(),
            'article' => null,
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('news.manage');

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.news.create'));
        }

        $data = $this->buildData($request, $validated);

        $imageResult = $this->handleImage($request, null, null);

        if ($imageResult['error'] !== null) {
            $this->error($imageResult['error']);

            return $this->back($request, $this->url->route('admin.news.create'));
        }

        if ($imageResult['key'] !== null) {
            $data['image_key'] = $imageResult['key'];
        }

        $data['author_id'] = $this->currentUser()->id;

        $id = $this->news->create($data);

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'news',
            $id,
            sprintf('Notizia creata: %s', $data['title']),
            ['status' => $data['status']],
        );

        $this->success('Notizia creata.');

        return $this->redirectToRoute('admin.news.edit', ['id' => $id]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('news.manage');

        $article = $this->news->find($request->routeInt('id'));

        if ($article === null) {
            $this->notFound('Notizia non trovata.');
        }

        return $this->render('admin/news/form.twig', [
            'seo' => $this->seo('Modifica notizia')->withNoindex(),
            'article' => $article,
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('news.manage');

        $id = $request->routeInt('id');
        $article = $this->news->find($id);

        if ($article === null) {
            $this->notFound('Notizia non trovata.');
        }

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.news.edit', ['id' => $id]));
        }

        $data = $this->buildData($request, $validated);

        // Lo slug si aggiorna solo su richiesta esplicita: cambiarlo rompe i
        // link gia condivisi sui social e azzera il posizionamento acquisito.
        if (! $request->bool('update_slug')) {
            unset($data['slug']);
        }

        $imageResult = $this->handleImage($request, $article->imageKey, null);

        if ($imageResult['error'] !== null) {
            $this->error($imageResult['error']);

            return $this->back($request, $this->url->route('admin.news.edit', ['id' => $id]));
        }

        if ($imageResult['key'] !== null) {
            $data['image_key'] = $imageResult['key'];
        }

        if ($request->bool('remove_image') && $article->imageKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_NEWS, $article->imageKey);
            $data['image_key'] = null;
        }

        $this->news->update($id, $data);

        $wasPublished = $article->status === News::STATUS_PUBLISHED;
        $isPublished = $data['status'] === News::STATUS_PUBLISHED;

        $action = match (true) {
            ! $wasPublished && $isPublished => AuditLogger::CONTENT_PUBLISHED,
            $wasPublished && ! $isPublished => AuditLogger::CONTENT_UNPUBLISHED,
            default => AuditLogger::CONTENT_UPDATED,
        };

        $this->audit->log($action, 'news', $id, sprintf('Notizia aggiornata: %s', $data['title']), [
            'status' => $data['status'],
        ]);

        $this->success('Notizia aggiornata.');

        return $this->redirectToRoute('admin.news.edit', ['id' => $id]);
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('news.manage');

        $id = $request->routeInt('id');
        $article = $this->news->find($id);

        if ($article === null) {
            $this->notFound('Notizia non trovata.');
        }

        $this->news->delete($id);

        // I file dell'immagine restano su disco: il soft delete e reversibile
        // direttamente a database, e senza immagine il ripristino sarebbe monco.
        $this->audit->log(
            AuditLogger::CONTENT_DELETED,
            'news',
            $id,
            sprintf('Notizia eliminata: %s', $article->title),
        );

        $this->success('Notizia eliminata.');

        return $this->redirectToRoute('admin.news.index');
    }

    // -----------------------------------------------------------------------
    //  Supporto
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null Dati validati, oppure null in caso di errori. */
    private function validateInput(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('title', 'Il titolo')->max('title', 200, 'Il titolo')
            ->optional('excerpt')->max('excerpt', 400, 'L estratto')
            ->optional('meta_title')->max('meta_title', 200, 'Il titolo SEO')
            ->optional('meta_description')->max('meta_description', 300, 'La descrizione SEO')
            ->in('status', [News::STATUS_DRAFT, News::STATUS_PUBLISHED, News::STATUS_ARCHIVED], 'Lo stato')
            ->date('published_date', 'La data di pubblicazione')
            ->time('published_time', 'L orario di pubblicazione');

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
        $status = (string) ($validated['status'] ?? News::STATUS_DRAFT);

        $publishedAt = Dates::combineToDatabase(
            $validated['published_date'] ?? null,
            $validated['published_time'] ?? null,
        );

        // Pubblicare senza indicare una data significa "adesso": chiedere anche
        // la data quando si preme "pubblica" sarebbe un attrito inutile.
        if ($status === News::STATUS_PUBLISHED && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        return [
            'title' => (string) $validated['title'],
            'slug' => Str::slug((string) ($request->string('slug') !== '' ? $request->string('slug') : $validated['title'])),
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $this->sanitizer->clean((string) $request->post('content', '')),
            'image_alt' => $request->nullableString('image_alt'),
            'status' => $status,
            'published_at' => $publishedAt,
            'is_featured' => $request->bool('is_featured') ? 1 : 0,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ];
    }

    /** @return array{key: string|null, error: string|null} */
    private function handleImage(Request $request, ?string $previousKey, ?string $previousExtension): array
    {
        if (! $request->hasFile('image')) {
            return ['key' => null, 'error' => null];
        }

        $file = $request->file('image');

        if ($file === null) {
            return ['key' => null, 'error' => null];
        }

        $result = $this->images->store($file, MediaPaths::COLLECTION_NEWS, $previousKey, $previousExtension);

        return ['key' => $result['key'], 'error' => $result['error']];
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            News::STATUS_DRAFT => 'Bozza',
            News::STATUS_PUBLISHED => 'Pubblicata',
            News::STATUS_ARCHIVED => 'Archiviata',
        ];
    }
}
