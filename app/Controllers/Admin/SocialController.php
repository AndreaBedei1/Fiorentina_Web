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
use App\Models\SocialPost;
use App\Repositories\SocialPostRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Services\Social\SocialService;
use App\Validation\Validator;

/**
 * Vetrina social dal pannello.
 *
 * Prendere i contenuti dalle API di Instagram e Facebook richiede un'app Meta,
 * un account professionale e un token che scade ogni sessanta giorni. Per un
 * gruppo che pubblica qualche post a settimana e un impegno sproporzionato al
 * risultato, e soprattutto un impegno ricorrente: ogni due mesi qualcuno deve
 * ricordarsi di rinnovare il token, o la sezione si svuota.
 *
 * Qui si fa la stessa cosa a mano e in mezzo minuto: si incolla il link del
 * post, si carica l'immagine e si scrive una riga. Il risultato in pagina e
 * identico, non scade mai e non dipende da nessun servizio esterno.
 *
 * Le due strade convivono: se un giorno arrivassero i token, i contenuti
 * scaricati si affiancherebbero a quelli scelti a mano senza sovrascriverli.
 */
final class SocialController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly SocialPostRepository $posts,
        private readonly SocialService $social,
        private readonly SimpleImageService $images,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('social.manage');

        return $this->render('admin/social/index.twig', [
            'seo' => $this->seo('Contenuti social')->withNoindex(),
            'posts' => $this->posts->allForAdmin(60),
            'isMock' => $this->social->isMockMode(),
            'lastSync' => $this->social->lastSyncedAt(),
            'providers' => self::PIATTAFORME,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('social.manage');

        return $this->render('admin/social/form.twig', [
            'seo' => $this->seo('Nuovo contenuto social')->withNoindex(),
            'post' => null,
            'providers' => self::PIATTAFORME,
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('social.manage');

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.social.create'));
        }

        $immagine = $this->handleImage($request, null);

        if ($immagine['error'] !== null) {
            $this->error($immagine['error']);

            return $this->back($request, $this->url->route('admin.social.create'));
        }

        if ($immagine['key'] === null) {
            $this->error('Serve un\'immagine: e quello che si vede in homepage.');

            return $this->back($request, $this->url->route('admin.social.create'));
        }

        $data = $this->buildData($request, $validated);
        $data['local_thumb_key'] = $immagine['key'];

        $id = $this->posts->createManual($data);

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'social_post',
            $id,
            sprintf('Contenuto social aggiunto a mano (%s)', $data['provider']),
        );

        $this->success('Contenuto aggiunto. Lo trovi in homepage.');

        return $this->redirectToRoute('admin.social.index');
    }

    public function edit(Request $request): Response
    {
        $this->authorize('social.manage');

        $post = $this->posts->find($request->routeInt('id'));

        if ($post === null) {
            $this->notFound('Contenuto non trovato.');
        }

        if (! $post->isManual) {
            $this->error('I contenuti scaricati dalle API non si modificano: cambia il post sul social.');

            return $this->redirectToRoute('admin.social.index');
        }

        return $this->render('admin/social/form.twig', [
            'seo' => $this->seo('Modifica contenuto social')->withNoindex(),
            'post' => $post,
            'providers' => self::PIATTAFORME,
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('social.manage');

        $id = $request->routeInt('id');
        $post = $this->posts->find($id);

        if ($post === null) {
            $this->notFound('Contenuto non trovato.');
        }

        if (! $post->isManual) {
            $this->error('I contenuti scaricati dalle API non si modificano.');

            return $this->redirectToRoute('admin.social.index');
        }

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.social.edit', ['id' => $id]));
        }

        $immagine = $this->handleImage($request, $post->localThumbKey);

        if ($immagine['error'] !== null) {
            $this->error($immagine['error']);

            return $this->back($request, $this->url->route('admin.social.edit', ['id' => $id]));
        }

        $data = $this->buildData($request, $validated);

        // Senza un nuovo file si tiene l'immagine che c'era: sostituirla con
        // null la farebbe sparire dalla homepage a ogni modifica di didascalia.
        if ($immagine['key'] !== null) {
            $data['local_thumb_key'] = $immagine['key'];
        }

        $this->posts->updateManual($id, $data);

        $this->audit->log(AuditLogger::CONTENT_UPDATED, 'social_post', $id, 'Contenuto social aggiornato');
        $this->success('Contenuto aggiornato.');

        return $this->redirectToRoute('admin.social.index');
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('social.manage');

        $id = $request->routeInt('id');
        $post = $this->posts->find($id);

        if ($post === null) {
            $this->notFound('Contenuto non trovato.');
        }

        $chiave = $this->posts->delete($id);

        if ($chiave !== null) {
            $this->images->delete(MediaPaths::COLLECTION_SOCIAL, $chiave);
        }

        $this->audit->log(AuditLogger::CONTENT_DELETED, 'social_post', $id, 'Contenuto social rimosso');
        $this->success('Contenuto rimosso.');

        return $this->redirectToRoute('admin.social.index');
    }

    /** Mostra o nasconde un contenuto senza cancellarlo. */
    public function toggle(Request $request): Response
    {
        $this->authorize('social.manage');

        $id = $request->routeInt('id');
        $post = $this->posts->find($id);

        if ($post === null) {
            $this->notFound('Contenuto non trovato.');
        }

        $this->posts->setVisibility($id, ! $post->isVisible);

        $this->success($post->isVisible ? 'Contenuto nascosto.' : 'Contenuto di nuovo visibile.');

        return $this->redirectToRoute('admin.social.index');
    }

    /** Sincronizzazione immediata, per chi ha configurato i token. */
    public function sync(Request $request): Response
    {
        $this->authorize('social.manage');

        $report = $this->social->sync();

        foreach ($report->errors as $errore) {
            $this->error($errore);
        }

        if (! $report->hasErrors()) {
            $this->success($report->summary());
        }

        return $this->redirectToRoute('admin.social.index');
    }

    // -----------------------------------------------------------------------

    /** @var array<string, string> */
    private const PIATTAFORME = [
        SocialPost::PROVIDER_INSTAGRAM => 'Instagram',
        SocialPost::PROVIDER_FACEBOOK => 'Facebook',
        SocialPost::PROVIDER_YOUTUBE => 'YouTube',
    ];

    /** @return array<string, mixed>|null */
    private function validateInput(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('permalink', 'Il link al post')
            ->url('permalink', 'Il link al post')
            ->max('permalink', 500, 'Il link al post')
            ->in('provider', array_keys(self::PIATTAFORME), 'La piattaforma')
            ->optional('caption')->max('caption', 500, 'La didascalia')
            ->date('published_date', 'La data');

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
        $piattaforma = (string) ($validated['provider'] ?? SocialPost::PROVIDER_INSTAGRAM);

        return [
            'provider' => $piattaforma,
            'permalink' => (string) $validated['permalink'],
            // I contenuti a mano sono sempre un'immagine che rimanda al post:
            // il sito non ospita il video, lo apre sul social.
            'media_type' => $piattaforma === SocialPost::PROVIDER_YOUTUBE ? 'video' : 'image',
            'caption' => $validated['caption'] ?? null,
            'published_at' => Dates::combineToDatabase(
                $validated['published_date'] ?? null,
                null,
            ) ?? date('Y-m-d H:i:s'),
            'is_visible' => $request->bool('is_visible') ? 1 : 0,
        ];
    }

    /**
     * @return array{key: string|null, error: string|null}
     */
    private function handleImage(Request $request, ?string $previousKey): array
    {
        if (! $request->hasFile('image')) {
            return ['key' => null, 'error' => null];
        }

        $file = $request->file('image');

        if ($file === null) {
            return ['key' => null, 'error' => null];
        }

        $result = $this->images->store($file, MediaPaths::COLLECTION_SOCIAL, $previousKey);

        return ['key' => $result['key'], 'error' => $result['error']];
    }
}
