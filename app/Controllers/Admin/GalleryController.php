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
use App\Models\Album;
use App\Repositories\AlbumRepository;
use App\Repositories\PhotoRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Media\PhotoService;
use App\Validation\Validator;

/**
 * Gestione di album e fotografie.
 *
 * Il caricamento multiplo passa da un endpoint dedicato che risponde in JSON:
 * così la barra di avanzamento e reale, l'esito e per file e l'amministratore
 * non resta davanti a una pagina bloccata mentre carica trenta immagini.
 */
final class GalleryController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly PhotoService $photoService,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    // -----------------------------------------------------------------------
    //  Album
    // -----------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $this->authorize('gallery.manage');

        return $this->render('admin/gallery/index.twig', [
            'seo' => $this->seo('Galleria')->withNoindex(),
            'paginator' => $this->albums->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                basePath: $this->url->route('admin.gallery.index'),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('gallery.manage');

        return $this->render('admin/gallery/form.twig', [
            'seo' => $this->seo('Nuovo album')->withNoindex(),
            'album' => null,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $validated = $this->validateAlbum($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.gallery.create'));
        }

        $id = $this->albums->create($this->buildAlbumData($request, $validated) + [
            'created_by' => $this->currentUser()->id,
        ]);

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'album',
            $id,
            sprintf('Album creato: %s', $validated['title']),
        );

        $this->success('Album creato. Ora puoi caricare le fotografie.');

        return $this->redirectToRoute('admin.gallery.edit', ['id' => $id]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $album = $this->albums->find($request->routeInt('id'));

        if ($album === null) {
            $this->notFound('Album non trovato.');
        }

        return $this->render('admin/gallery/form.twig', [
            'seo' => $this->seo('Modifica album')->withNoindex(),
            'album' => $album,
            'photos' => $this->photos->allForAlbum($album->id),
            'categories' => $this->categoryOptions(),
            'maxUploadBytes' => $this->config->int('image.max_upload_bytes', 16 * 1024 * 1024),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $id = $request->routeInt('id');
        $album = $this->albums->find($id);

        if ($album === null) {
            $this->notFound('Album non trovato.');
        }

        $validated = $this->validateAlbum($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.gallery.edit', ['id' => $id]));
        }

        $data = $this->buildAlbumData($request, $validated);

        $this->albums->update($id, $data);

        $this->audit->log(
            AuditLogger::CONTENT_UPDATED,
            'album',
            $id,
            sprintf('Album aggiornato: %s', $validated['title']),
        );

        $this->success('Album aggiornato.');

        return $this->redirectToRoute('admin.gallery.edit', ['id' => $id]);
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $id = $request->routeInt('id');
        $album = $this->albums->find($id);

        if ($album === null) {
            $this->notFound('Album non trovato.');
        }

        // Le fotografie vengono rimosse davvero, file compresi: un album
        // eliminato non deve lasciare centinaia di immagini orfane su disco.
        $deletedPhotos = $this->photoService->deleteAllForAlbum($id);
        $this->albums->delete($id);

        $this->audit->log(
            AuditLogger::CONTENT_DELETED,
            'album',
            $id,
            sprintf('Album eliminato: %s', $album->title),
            ['photos_deleted' => $deletedPhotos],
        );

        $this->success(sprintf('Album eliminato insieme a %d fotografie.', $deletedPhotos));

        return $this->redirectToRoute('admin.gallery.index');
    }

    // -----------------------------------------------------------------------
    //  Fotografie
    // -----------------------------------------------------------------------

    /** Caricamento multiplo. Risponde in JSON per la barra di avanzamento. */
    public function uploadPhotos(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $albumId = $request->routeInt('id');
        $album = $this->albums->find($albumId);

        if ($album === null) {
            return $this->json(['ok' => false, 'message' => 'Album non trovato.'], 404);
        }

        $files = $request->fileList('photos');

        if ($files === []) {
            return $this->json(['ok' => false, 'message' => 'Nessun file ricevuto.'], 422);
        }

        $report = $this->photoService->uploadToAlbum($albumId, $files, $this->currentUser());

        return $this->json([
            'ok' => $report->uploadedCount > 0,
            'uploaded' => $report->uploadedCount,
            'message' => $report->summaryMessage(),
            'errors' => $report->errors,
        ], $report->uploadedCount > 0 ? 200 : 422);
    }

    public function destroyPhoto(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $photoId = $request->routeInt('photoId');
        $photo = $this->photos->find($photoId);

        if ($photo === null) {
            $this->notFound('Fotografia non trovata.');
        }

        $albumId = $photo->albumId;
        $this->photoService->delete($photoId, $this->currentUser());

        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'message' => 'Fotografia eliminata.']);
        }

        $this->success('Fotografia eliminata.');

        return $this->redirectToRoute('admin.gallery.edit', ['id' => $albumId]);
    }

    public function setCover(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $photoId = $request->routeInt('photoId');
        $photo = $this->photos->find($photoId);

        if ($photo === null) {
            $this->notFound('Fotografia non trovata.');
        }

        $this->albums->update($photo->albumId, ['cover_photo_id' => $photoId]);
        $this->success('Copertina aggiornata.');

        return $this->redirectToRoute('admin.gallery.edit', ['id' => $photo->albumId]);
    }

    /** Riordino via trascinamento: riceve la nuova sequenza di ID. */
    public function reorderPhotos(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $albumId = $request->routeInt('id');

        if ($this->albums->find($albumId) === null) {
            return $this->json(['ok' => false, 'message' => 'Album non trovato.'], 404);
        }

        $order = $request->intList('order');

        if ($order === []) {
            return $this->json(['ok' => false, 'message' => 'Ordine non valido.'], 422);
        }

        $this->photos->reorder($albumId, $order);
        $this->albums->refreshCounters($albumId);

        return $this->json(['ok' => true, 'message' => 'Ordine salvato.']);
    }

    /** Riapplica la filigrana a tutto l'album, dopo un cambio di impostazioni. */
    public function regenerate(Request $request): Response
    {
        $this->authorize('gallery.manage');

        $albumId = $request->routeInt('id');

        if ($this->albums->find($albumId) === null) {
            $this->notFound('Album non trovato.');
        }

        $result = $this->photoService->regenerateAlbum($albumId);

        $this->success(sprintf(
            '%d fotografie rielaborate%s.',
            $result['processed'],
            $result['failed'] > 0 ? sprintf(', %d non riuscite', $result['failed']) : '',
        ));

        return $this->redirectToRoute('admin.gallery.edit', ['id' => $albumId]);
    }

    // -----------------------------------------------------------------------
    //  Supporto
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function validateAlbum(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('title', 'Il titolo')->max('title', 200, 'Il titolo')
            ->optional('description')->max('description', 2000, 'La descrizione')
            ->date('event_date', 'La data');

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
    private function buildAlbumData(Request $request, array $validated): array
    {
        $eventDate = $validated['event_date'] ?? null;

        return [
            'title' => (string) $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_date' => $eventDate,
            // L'anno duplicato consente di filtrare la galleria con un confronto
            // indicizzato invece che con una funzione sulla colonna data.
            'year' => $eventDate !== null ? (int) substr((string) $eventDate, 0, 4) : null,
        ];
    }

    /** @return array<string, string> */
    private function categoryOptions(): array
    {
        return [
            'stadio' => 'Stadio',
            'trasferte' => 'Trasferte',
            'eventi' => 'Eventi',
            'raduni' => 'Raduni',
            'storico' => 'Archivio storico',
            'altro' => 'Altro',
        ];
    }

}
