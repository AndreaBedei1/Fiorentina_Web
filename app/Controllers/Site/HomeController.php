<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Repositories\AlbumRepository;
use App\Repositories\NewsRepository;
use App\Repositories\ProductRepository;
use App\Services\AuthService;
use App\Services\Football\FootballService;
use App\Services\SettingsService;
use App\Services\Social\SocialService;
use App\Repositories\EventRepository;

/**
 * Homepage.
 *
 * Assembla le sezioni pescando da piu repository, ma nessuna di queste letture
 * esce dal sito: partite e contenuti social arrivano dalla copia locale
 * alimentata dal cron. La homepage e la pagina piu visitata, e deve restare
 * veloce anche quando un servizio esterno non risponde.
 */
final class HomeController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly NewsRepository $news,
        private readonly EventRepository $events,
        private readonly AlbumRepository $albums,
        private readonly ProductRepository $products,
        private readonly FootballService $football,
        private readonly SocialService $social,
        private readonly SettingsService $settings,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $groupName = $this->settings->string('site_group_name', 'Baraonda Fiorentina');

        $seo = $this->seo($groupName, $this->settings->string('site_description'))
            ->withCanonical($this->url->absolute('/'))
            ->withType('website')
            ->withStructuredData($this->organizationSchema($groupName));

        return $this->render('site/home.twig', [
            'seo' => $seo,
            'latestNews' => $this->news->latestPublished(3),
            'upcomingEvents' => $this->events->upcoming(3),
            'nextMatch' => $this->settings->bool('home_show_next_match', true)
                ? $this->football->nextMatch()
                : null,
            'recentAlbums' => $this->albums->latestPublished(3),
            'featuredProducts' => $this->products->featured(4),
            'socialPosts' => $this->settings->bool('home_show_social', true)
                ? $this->social->latest(6)
                : [],
            'showTeamLogos' => $this->settings->bool('home_show_team_logos', false),
            'isHome' => true,
        ]);
    }

    /**
     * Dati strutturati dell'organizzazione.
     *
     * Aiutano i motori di ricerca a mostrare nome, logo e profili social nella
     * scheda del sito. Includiamo solo i social effettivamente configurati.
     *
     * @return array<string, mixed>
     */
    private function organizationSchema(string $groupName): array
    {
        $sameAs = [];

        foreach (['social_instagram_url', 'social_facebook_url', 'social_youtube_url'] as $key) {
            $value = $this->settings->string($key);

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) !== false) {
                $sameAs[] = $value;
            }
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'SportsOrganization',
            'name' => $groupName,
            'url' => $this->url->baseUrl(),
            'description' => $this->settings->string('site_description'),
            'sport' => 'Calcio',
        ];

        $email = $this->settings->string('contact_email');

        if ($email !== '') {
            $schema['email'] = $email;
        }

        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        $founded = $this->settings->int('site_founded_year');

        if ($founded > 1900) {
            $schema['foundingDate'] = (string) $founded;
        }

        return $schema;
    }
}
