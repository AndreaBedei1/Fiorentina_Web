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
use App\Core\Support\Dates;
use App\Core\View\ViewRenderer;
use DateTimeImmutable;
use App\Repositories\EventCategoryRepository;
use App\Repositories\EventRepository;
use App\Services\AuthService;
use App\Services\CalendarService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;

/**
 * Elenco e dettaglio degli appuntamenti del gruppo.
 *
 * In fondo all'elenco c'e anche il calendario mensile, che prima aveva una
 * pagina propria. Sono la stessa cosa guardata in due modi - l'elenco per
 * sapere cosa viene dopo, la griglia per orientarsi nel mese - e tenerli
 * separati costringeva a saltare da una pagina all'altra.
 */
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
        private readonly CalendarService $calendar,
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

        $periodo = $this->calendar->normalizeMonth(
            $request->nullableInt('anno'),
            $request->nullableInt('mese'),
        );

        $mese = (new DateTimeImmutable())->setDate($periodo['year'], $periodo['month'], 1)->setTime(0, 0);

        return $this->render('site/events/index.twig', [
            'seo' => $seo,
            'paginator' => $paginator,
            'categories' => $this->categories->all(),
            'activeCategory' => $categorySlug,
            'showPast' => $showPast,

            // Calendario mensile in coda alla pagina.
            'weeks' => $this->calendar->monthGrid($periodo['year'], $periodo['month']),
            'upcoming' => $this->calendar->upcoming(15),
            'currentMonth' => $mese,
            'monthLabel' => Dates::monthName($periodo['month']) . ' ' . $periodo['year'],
            'previousMonth' => $mese->modify('-1 month'),
            'nextMonth' => $mese->modify('+1 month'),
            'showTeamLogos' => $this->settings->bool('home_show_team_logos', false),
            'today' => (new DateTimeImmutable())->format('Y-m-d'),
        ]);
    }

    public function show(Request $request): Response
    {
        // L'indirizzo e "7-cena-sociale": conta il numero, la coda serve a
        // rendere leggibile il collegamento. Cambiare il titolo non spezza
        // quello che e gia stato condiviso.
        $riferimento = (string) $request->route('riferimento');
        $event = $this->events->findPublished((int) $riferimento);

        if ($event === null) {
            $this->notFound('L\'evento che cerchi non esiste o non è più pubblicato.');
        }

        if ($riferimento !== $event->urlKey()) {
            return RedirectResponse::to($this->url->route('events.show', ['riferimento' => $event->urlKey()]), 301);
        }

        $imageUrl = $event->imageKey !== null
            ? $this->url->absolute($this->media->url(MediaPaths::COLLECTION_EVENTS, $event->imageKey, MediaPaths::SIZE_LARGE, 'jpg'))
            : null;

        $seo = $this->seo($event->seoTitle(), $event->seoDescription())
            ->withType('article')
            ->withImage($imageUrl)
            ->withCanonical($this->url->absoluteRoute('events.show', ['riferimento' => $event->urlKey()]))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Eventi', 'url' => $this->url->route('events.index')],
                ['name' => $event->title, 'url' => $this->url->route('events.show', ['riferimento' => $event->urlKey()])],
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
     * Con questi Google può mostrare data e luogo direttamente nei risultati,
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
            'eventStatus' => 'https://schema.org/EventScheduled',
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
