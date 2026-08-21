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

        return $this->render('admin/news/index.twig', [
            'seo' => $this->seo('Notizie')->withNoindex(),
            'paginator' => $this->news->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                basePath: $this->url->route('admin.news.index'),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('news.manage');

        return $this->render('admin/news/form.twig', [
            'seo' => $this->seo('Nuova notizia')->withNoindex(),
            'article' => null,
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

        $validated = $this->validateInput($request, $article);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.news.edit', ['id' => $id]));
        }

        $data = $this->buildData($request, $validated);

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

        $this->audit->log(
            AuditLogger::CONTENT_UPDATED,
            'news',
            $id,
            sprintf('Notizia aggiornata: %s', $data['title']),
        );

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
    private function validateInput(Request $request, ?News $article = null): ?array
    {
        $validator = Validator::make($request->all())
            ->required('title', 'Il titolo')->max('title', 200, 'Il titolo')
            ->optional('excerpt')->max('excerpt', 400, 'L estratto')
            ->optional('image_alt')->max('image_alt', 200, 'Il testo alternativo');

        // Una fotografia senza descrizione, per chi non la vede, non esiste.
        // La descrizione si chiede quindi ogni volta che una fotografia c'è:
        // appena caricata, oppure gia presente e non rimossa adesso.
        $restaQuellaDiPrima = $article !== null
            && $article->imageKey !== null
            && ! $request->bool('remove_image');

        if (($request->hasFile('image') || $restaQuellaDiPrima)
            && trim((string) $request->post('image_alt', '')) === ''
        ) {
            $validator->addError('image_alt', 'Descrivi la fotografia: serve a chi non può vederla.');
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
        // La data non si chiede: una notizia appena scritta e una notizia di
        // oggi. In modifica resta quella originale, perche cambiare il testo
        // di un articolo di marzo non lo sposta a giugno.
        return [
            'title' => (string) $validated['title'],
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $this->sanitizer->clean((string) $request->post('content', '')),
            'image_alt' => $request->nullableString('image_alt'),
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

}
