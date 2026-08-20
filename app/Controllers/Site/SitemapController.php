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
use App\Repositories\EventRepository;
use App\Repositories\NewsRepository;
use App\Repositories\ProductRepository;
use App\Services\AuthService;

/**
 * Sitemap XML e robots.txt generati dinamicamente.
 *
 * Generarli a runtime invece che come file statici significa che restano
 * sempre allineati ai contenuti pubblicati, senza che nessuno debba
 * ricordarsi di rigenerarli dopo aver aggiunto una notizia.
 */
final class SitemapController extends Controller
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
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function sitemap(Request $request): Response
    {
        $entries = [];

        // Pagine fisse, con priorita decrescente per importanza.
        $staticPages = [
            ['route' => 'home', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['route' => 'page.join', 'priority' => '0.9', 'changefreq' => 'monthly'],
            ['route' => 'page.about', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['route' => 'news.index', 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['route' => 'events.index', 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['route' => 'calendar.index', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['route' => 'gallery.index', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['route' => 'shop.index', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['route' => 'contact.show', 'priority' => '0.6', 'changefreq' => 'yearly'],
            ['route' => 'page.privacy', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['route' => 'page.cookies', 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        foreach ($staticPages as $page) {
            $entries[] = [
                'loc' => $this->url->absoluteRoute($page['route']),
                'lastmod' => date('Y-m-d'),
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
            ];
        }

        foreach ($this->news->publishedForSitemap() as $item) {
            $entries[] = $this->entry('news.show', $item, '0.7', 'monthly');
        }

        foreach ($this->events->publishedForSitemap() as $item) {
            $entries[] = $this->entry('events.show', $item, '0.6', 'monthly');
        }

        foreach ($this->albums->publishedForSitemap() as $item) {
            $entries[] = $this->entry('gallery.show', $item, '0.6', 'monthly');
        }

        foreach ($this->products->publishedForSitemap() as $item) {
            $entries[] = $this->entry('shop.show', $item, '0.6', 'monthly');
        }

        $xml = $this->view->render('site/sitemap.xml.twig', ['entries' => $entries]);

        return Response::xml($xml)->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        $lines = [
            'User-agent: *',
            // L'area riservata, il carrello è il percorso d'ordine non hanno
            // alcun valore per i motori di ricerca.
            'Disallow: /admin',
            'Disallow: /carrello',
            'Disallow: /ordine',
            'Disallow: /uploads/social',
            '',
            'Sitemap: ' . $this->url->absolute('/sitemap.xml'),
            '',
        ];

        return Response::text(implode("\n", $lines))
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * @param array{slug: string, updated_at: string} $item
     * @return array{loc: string, lastmod: string, changefreq: string, priority: string}
     */
    private function entry(string $routeName, array $item, string $priority, string $changefreq): array
    {
        return [
            'loc' => $this->url->absoluteRoute($routeName, ['slug' => $item['slug']]),
            'lastmod' => substr($item['updated_at'], 0, 10),
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }
}
