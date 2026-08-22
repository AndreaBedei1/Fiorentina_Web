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
use App\Core\View\ViewRenderer;
use App\Repositories\EventCategoryRepository;
use App\Repositories\EventRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\HtmlSanitizer;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Validation\Validator;

/** Gestione degli eventi del gruppo dall'area riservata. */
final class EventController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly EventRepository $events,
        private readonly EventCategoryRepository $categories,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SimpleImageService $images,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('events.manage');

        return $this->render('admin/events/index.twig', [
            'seo' => $this->seo('Eventi')->withNoindex(),
            'paginator' => $this->events->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                basePath: $this->url->route('admin.events.index'),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('events.manage');

        return $this->render('admin/events/form.twig', [
            'seo' => $this->seo('Nuovo evento')->withNoindex(),
            'event' => null,
            'categoryOptions' => $this->categories->options(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('events.manage');

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.events.create'));
        }

        $data = $this->buildData($request, $validated);

        $image = $this->storeImage($request, null);

        if ($image['error'] !== null) {
            $this->error($image['error']);

            return $this->back($request, $this->url->route('admin.events.create'));
        }

        if ($image['key'] !== null) {
            $data['image_key'] = $image['key'];
        }

        $data['created_by'] = $this->currentUser()->id;

        $id = $this->events->create($data);

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'event',
            $id,
            sprintf('Evento creato: %s', $data['title']),
            ['starts_at' => $data['starts_at']],
        );

        $this->success('Evento creato.');

        return $this->redirectToRoute('admin.events.edit', ['id' => $id]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('events.manage');

        $event = $this->events->find($request->routeInt('id'));

        if ($event === null) {
            $this->notFound('Evento non trovato.');
        }

        return $this->render('admin/events/form.twig', [
            'seo' => $this->seo('Modifica evento')->withNoindex(),
            'event' => $event,
            'categoryOptions' => $this->categories->options(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('events.manage');

        $id = $request->routeInt('id');
        $event = $this->events->find($id);

        if ($event === null) {
            $this->notFound('Evento non trovato.');
        }

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.events.edit', ['id' => $id]));
        }

        $data = $this->buildData($request, $validated);

        $image = $this->storeImage($request, $event->imageKey);

        if ($image['error'] !== null) {
            $this->error($image['error']);

            return $this->back($request, $this->url->route('admin.events.edit', ['id' => $id]));
        }

        if ($image['key'] !== null) {
            $data['image_key'] = $image['key'];
        }

        if ($request->bool('remove_image') && $event->imageKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_EVENTS, $event->imageKey);
            $data['image_key'] = null;
        }

        $this->events->update($id, $data);

        $this->audit->log(
            AuditLogger::CONTENT_UPDATED,
            'event',
            $id,
            sprintf('Evento aggiornato: %s', $data['title']),
        );

        $this->success('Evento aggiornato.');

        return $this->redirectToRoute('admin.events.edit', ['id' => $id]);
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('events.manage');

        $id = $request->routeInt('id');
        $event = $this->events->find($id);

        if ($event === null) {
            $this->notFound('Evento non trovato.');
        }

        $this->events->delete($id);

        if ($event->imageKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_EVENTS, $event->imageKey);
        }

        $this->audit->log(
            AuditLogger::CONTENT_DELETED,
            'event',
            $id,
            sprintf('Evento eliminato: %s', $event->title),
        );

        $this->success('Evento eliminato.');

        return $this->redirectToRoute('admin.events.index');
    }

    // -----------------------------------------------------------------------
    //  Supporto
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function validateInput(Request $request): ?array
    {
        $categoryIds = array_map('strval', array_keys($this->categories->options()));

        $validator = Validator::make($request->all())
            ->required('title', 'Il titolo')->max('title', 200, 'Il titolo')
            ->optional('short_description')->max('short_description', 400, 'La descrizione breve')
            ->date('start_date', 'La data di inizio', required: true)
            ->time('start_time', 'L orario di inizio')
            ->date('end_date', 'La data di fine')
            ->time('end_time', 'L orario di fine')
            ->time('meeting_time', 'L orario di ritrovo')
            ->optional('location_name')->max('location_name', 150, 'Il luogo')
            ->optional('address')->max('address', 255, 'L indirizzo')
            ->optional('city')->max('city', 100, 'La citta')
            ->optional('meeting_point')->max('meeting_point', 255, 'Il punto di ritrovo')
            ->decimal('cost', 'Il costo', 0)
            ->optional('cost_note')->max('cost_note', 150, 'La nota sul costo')
            ->optional('contact_info')->max('contact_info', 255, 'I riferimenti')
            ->integer('seats', 'I posti disponibili', 0, 100000);

        if ($categoryIds !== []) {
            $validator->in('category_id', $categoryIds, 'La categoria');
        }

        // Un evento che finisce prima di cominciare non esiste: e quasi sempre
        // una data digitata storta, e senza questo controllo finirebbe in
        // calendario a occupare giorni all'indietro.
        $inizio = Dates::combineToDatabase(
            $request->string('start_date'),
            $request->string('start_time'),
        );
        $fine = Dates::combineToDatabase(
            $request->string('end_date'),
            $request->string('end_time'),
        );

        if ($inizio !== null && $fine !== null && $fine <= $inizio) {
            $validator->addError('end_date', 'La fine deve venire dopo l\'inizio.');
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
        $limitedSeats = $request->bool('limited_seats');

        return [
            'title' => (string) $validated['title'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $this->sanitizer->clean((string) $request->post('description', '')),
            'category_id' => $request->nullableInt('category_id'),
            'starts_at' => Dates::combineToDatabase($validated['start_date'] ?? null, $validated['start_time'] ?? null),
            'ends_at' => Dates::combineToDatabase($validated['end_date'] ?? null, $validated['end_time'] ?? null),
            'location_name' => $validated['location_name'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'meeting_at' => Dates::combineToDatabase($validated['start_date'] ?? null, $validated['meeting_time'] ?? null),
            'image_alt' => $request->nullableString('image_alt'),
            'cost' => $validated['cost'] ?? null,
            'cost_note' => $validated['cost_note'] ?? null,
            'info' => $request->nullableString('info'),
            'contact_info' => $validated['contact_info'] ?? null,
            'limited_seats' => $limitedSeats ? 1 : 0,
            // Il numero di posti ha senso solo se i posti sono dichiarati limitati.
            'seats' => $limitedSeats ? ($validated['seats'] ?? null) : null,
            'meta_title' => $request->nullableString('meta_title'),
            'meta_description' => $request->nullableString('meta_description'),
        ];
    }

    /** @return array{key: string|null, error: string|null} */
    private function storeImage(Request $request, ?string $previousKey): array
    {
        if (! $request->hasFile('image')) {
            return ['key' => null, 'error' => null];
        }

        $file = $request->file('image');

        if ($file === null) {
            return ['key' => null, 'error' => null];
        }

        $result = $this->images->store($file, MediaPaths::COLLECTION_EVENTS, $previousKey);

        return ['key' => $result['key'], 'error' => $result['error']];
    }

}
