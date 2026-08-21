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
use App\Repositories\NewsRepository;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;

/** Elenco e dettaglio delle notizie. */
final class NewsController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly NewsRepository $news,
        private readonly SettingsService $settings,
        private readonly MediaPaths $media,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $paginator = $this->news->paginatePublished($page, 9, $this->url->route('news.index'));

        $seo = $this->seo(
            'Notizie',
            'Le ultime novità di ' . $this->settings->string('site_group_name') . ': trasferte, iniziative, comunicazioni e vita del gruppo.',
        )
            ->withCanonical($this->url->absoluteRoute('news.index'))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Notizie', 'url' => $this->url->route('news.index')],
            ]);

        // Le pagine oltre la prima non vanno indicizzate: sono liste che
        // cambiano di continuo e produrrebbero contenuti quasi duplicati.
        if ($page > 1) {
            $seo->withNoindex();
        }

        return $this->render('site/news/index.twig', [
            'seo' => $seo,
            'paginator' => $paginator,
        ]);
    }

    public function show(Request $request): Response
    {
        // L'indirizzo e "12-trasferta-a-bologna": conta il numero davanti, la
        // coda serve solo a rendere leggibile il collegamento. Cosi il titolo
        // puo cambiare quante volte si vuole senza spezzare quello che e gia
        // stato condiviso.
        $riferimento = (string) $request->route('riferimento');
        $article = $this->news->findPublished((int) $riferimento);

        if ($article === null) {
            $this->notFound('La notizia che cerchi non esiste o non è più pubblicata.');
        }

        // Se si arriva da un vecchio indirizzo o da uno storpiato, si prosegue
        // su quello giusto: una pagina sola, un indirizzo solo.
        if ($riferimento !== $article->urlKey()) {
            return RedirectResponse::to($this->url->route('news.show', ['riferimento' => $article->urlKey()]), 301);
        }

        $imageUrl = $article->imageKey !== null
            ? $this->url->absolute($this->media->url(MediaPaths::COLLECTION_NEWS, $article->imageKey, MediaPaths::SIZE_LARGE, 'jpg'))
            : null;

        $seo = $this->seo($article->seoTitle(), $article->seoDescription())
            ->withType('article')
            ->withImage($imageUrl)
            ->withCanonical($this->url->absoluteRoute('news.show', ['riferimento' => $article->urlKey()]))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Notizie', 'url' => $this->url->route('news.index')],
                ['name' => $article->title, 'url' => $this->url->route('news.show', ['riferimento' => $article->urlKey()])],
            ])
            ->withStructuredData([
                '@context' => 'https://schema.org',
                '@type' => 'NewsArticle',
                'headline' => $article->title,
                'description' => $article->summary(160),
                'datePublished' => Dates::iso($article->publishedAt),
                'dateModified' => Dates::iso($article->updatedAt ?? $article->publishedAt),
                'author' => [
                    '@type' => 'Organization',
                    'name' => $this->settings->string('site_group_name'),
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $this->settings->string('site_group_name'),
                ],
                'image' => $imageUrl === null ? [] : [$imageUrl],
                'mainEntityOfPage' => $this->url->absoluteRoute('news.show', ['riferimento' => $article->urlKey()]),
            ]);

        return $this->render('site/news/show.twig', [
            'seo' => $seo,
            'article' => $article,
            'related' => $this->news->relatedTo($article->id, 3),
        ]);
    }
}
