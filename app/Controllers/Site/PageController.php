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
 * può riscriverle senza toccare il codice.
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

        [$testoIniziale, $testoFinale] = $this->dividiAllUltimoTitolo((string) $page->content);

        return $this->render('site/pages/about.twig', [
            'seo' => $this->seoFor($page, 'about'),
            'page' => $page,
            'testoIniziale' => $testoIniziale,
            'testoFinale' => $testoFinale,
            'members' => $this->organization->visibleMembers(),
            'foundedYear' => $this->settings->int('site_founded_year'),
        ]);
    }

    /**
     * Divide il testo della pagina in due parti, all'ultimo titolo di sezione.
     *
     * Serve alla pagina "Chi siamo", dove il testo scorre a sinistra e le due
     * tavole - tappe e numeri - stanno a destra. Le due parti diventano due
     * righe della griglia, e ogni riga allinea in alto cio che contiene: cosi
     * l'ultima sezione del testo parte alla stessa altezza della seconda
     * tavola, invece di finire dove capita.
     *
     * Se il testo non ha titoli, o ne ha uno solo, resta tutto nella prima
     * parte: meglio nessun allineamento che un taglio a meta di un discorso.
     *
     * @return array{0: string, 1: string}
     */
    private function dividiAllUltimoTitolo(string $html): array
    {
        if (preg_match_all('/<h2[\s>]/i', $html, $titoli, PREG_OFFSET_CAPTURE) < 2) {
            return [$html, ''];
        }

        $inizioUltimo = $titoli[0][count($titoli[0]) - 1][1];

        return [substr($html, 0, $inizioUltimo), substr($html, $inizioUltimo)];
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
            $this->notFound('Questa pagina non è ancora stata pubblicata.');
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
