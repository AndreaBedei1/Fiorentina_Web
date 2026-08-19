<?php

declare(strict_types=1);

namespace App\Controllers\Site;

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
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;

/** Elenco e dettaglio degli appuntamenti del gruppo. */
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
        private readonly SettingsService $settings,
        private readonly MediaPaths $media,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $showPast = $request->query('passati') === '1';
        $categorySlug = $request->string('categoria');
        $categorySlug = $categorySlug !== '' ? $categorySlug : null;

        $paginator = $this->events->paginatePublished(
            page: $page,
            perPage: 9,
            past: $showPast,
            categorySlug: $categorySlug,
            basePath: $this->url->route('events.index'),
        );

        $seo = $this->seo(
            $showPast ? 'Eventi passati' : 'Eventi e trasferte',
            'Trasferte, riunioni, cene sociali e raduni di ' . $this->settings->string('site_group_name') . '.',
        )
            ->withCanonical($this->url->absoluteRoute('events.index'))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Eventi', 'url' => $this->url->route('events.index')],
            ]);

        if ($page > 1 || $showPast || $categorySlug !== null) {
            $seo->withNoindex();
        }

        return $this->render('site/events/index.twig', [
            'seo' => $seo,
            'paginator' => $paginator,
            'categories' => $this->categories->all(),
            'activeCategory' => $categorySlug,
            'showPast' => $showPast,
        ]);
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->route('slug');
        $event = $this->events->findPublishedBySlug($slug);

        if ($event === null) {
            $this->notFound('L evento che cerchi non esiste o non e piu pubblicato.');
        }

        $imageUrl = $event->imageKey !== null
            ? $this->url->absolute($this->media->url(MediaPaths::COLLECTION_EVENTS, $event->imageKey, MediaPaths::SIZE_LARGE, 'jpg'))
            : null;

        $seo = $this->seo($event->seoTitle(), $event->seoDescription())
            ->withType('article')
            ->withImage($imageUrl)
            ->withCanonical($this->url->absoluteRoute('events.show', ['slug' => $event->slug]))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Eventi', 'url' => $this->url->route('events.index')],
                ['name' => $event->title, 'url' => $this->url->route('events.show', ['slug' => $event->slug])],
            ])
            ->withStructuredData($this->eventSchema($event, $imageUrl));

        return $this->render('site/events/show.twig', [
            'seo' => $seo,
            'event' => $event,
            'upcoming' => $this->events->upcoming(4),
        ]);
    }

    /**
     * Dati strutturati dell'evento.
     *
     * Con questi Google puo mostrare data e luogo direttamente nei risultati,
     * cosa che per trasferte e raduni fa una differenza concreta.
     *
     * @return array<string, mixed>
     */
    private function eventSchema(\App\Models\Event $event, ?string $imageUrl): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'startDate' => Dates::iso($event->startsAt),
            'description' => $event->summary(200),
            'eventStatus' => $event->isCancelled()
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'organizer' => [
                '@type' => 'Organization',
                'name' => $this->settings->string('site_group_name'),
                'url' => $this->url->baseUrl(),
            ],
        ];

        if ($event->endsAt !== null) {
            $schema['endDate'] = Dates::iso($event->endsAt);
        }

        if ($event->locationName !== null || $event->address !== null) {
            $schema['location'] = [
                '@type' => 'Place',
                'name' => $event->locationName ?? $event->city ?? 'Da definire',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $event->address,
                    'addressLocality' => $event->city,
                    'addressCountry' => 'IT',
                ],
            ];
        }

        if ($imageUrl !== null) {
            $schema['image'] = [$imageUrl];
        }

        return $schema;
    }
}
