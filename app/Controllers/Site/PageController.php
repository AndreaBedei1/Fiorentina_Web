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
use App\Models\Page;
use App\Repositories\OrganizationRepository;
use App\Repositories\PageRepository;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;

/**
 * Pagine editoriali gestite dagli amministratori.
 *
 * Chi siamo, Diventa socio, Privacy e Cookie policy hanno una rotta dedicata e
 * un template proprio, ma il contenuto arriva sempre dal database: il gruppo
 * puo riscriverle senza toccare il codice.
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
        private readonly OrganizationRepository $organization,
        private readonly SettingsService $settings,
        private readonly MediaPaths $media,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function about(Request $request): Response
    {
        $page = $this->requirePage(Page::SLUG_CHI_SIAMO);

        return $this->render('site/pages/about.twig', [
            'seo' => $this->seoFor($page, 'about'),
            'page' => $page,
            'members' => $this->organization->visibleMembers(),
            'foundedYear' => $this->settings->int('site_founded_year'),
        ]);
    }

    public function join(Request $request): Response
    {
        $page = $this->requirePage(Page::SLUG_DIVENTA_SOCIO);

        return $this->render('site/pages/join.twig', [
            'seo' => $this->seoFor($page, 'join'),
            'page' => $page,
            'membershipFee' => $this->settings->string('membership_fee'),
            'membershipContact' => $this->settings->string('membership_contact'),
        ]);
    }

    public function privacy(Request $request): Response
    {
        $page = $this->requirePage(Page::SLUG_PRIVACY);

        return $this->render('site/pages/legal.twig', [
            'seo' => $this->seoFor($page, 'privacy'),
            'page' => $page,
        ]);
    }

    public function cookies(Request $request): Response
    {
        $page = $this->requirePage(Page::SLUG_COOKIE);

        return $this->render('site/pages/legal.twig', [
            'seo' => $this->seoFor($page, 'cookies'),
            'page' => $page,
        ]);
    }

    private function requirePage(string $slug): Page
    {
        $page = $this->pages->findBySlug($slug);

        if ($page === null) {
            $this->notFound('Questa pagina non e ancora stata pubblicata.');
        }

        return $page;
    }

    private function seoFor(Page $page, string $routeSuffix): \App\Services\SeoMeta
    {
        $routeName = match ($routeSuffix) {
            'about' => 'page.about',
            'join' => 'page.join',
            'privacy' => 'page.privacy',
            default => 'page.cookies',
        };

        $imageUrl = $page->heroImageKey !== null
            ? $this->url->absolute($this->media->url(MediaPaths::COLLECTION_PAGES, $page->heroImageKey, MediaPaths::SIZE_LARGE, 'jpg'))
            : null;

        return $this->seo($page->seoTitle(), $page->seoDescription())
            ->withImage($imageUrl)
            ->withCanonical($this->url->absoluteRoute($routeName))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => $page->title, 'url' => $this->url->route($routeName)],
            ]);
    }
}
