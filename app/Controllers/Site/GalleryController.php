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
use App\Core\View\ViewRenderer;
use App\Models\Album;
use App\Repositories\AlbumRepository;
use App\Repositories\PhotoRepository;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;

/**
 * Galleria fotografica.
 *
 * L'archivio del gruppo e ampio: la galleria e organizzata in album e ogni
 * elenco e paginato. Non esiste una vista che carichi tutte le fotografie
 * insieme, ed e una scelta deliberata.
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
        private readonly SettingsService $settings,
        private readonly MediaPaths $media,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $year = $request->nullableInt('anno');

        $paginator = $this->albums->paginatePublished(
            page: $page,
            perPage: 12,
            year: $year,
            basePath: $this->url->route('gallery.index'),
        );

        $seo = $this->seo(
            'Galleria fotografica',
            'Le fotografie di ' . $this->settings->string('site_group_name') . ': stadio, trasferte, eventi e raduni.',
        )
            ->withCanonical($this->url->absoluteRoute('gallery.index'))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Galleria', 'url' => $this->url->route('gallery.index')],
            ]);

        if ($page > 1 || $year !== null) {
            $seo->withNoindex();
        }

        return $this->render('site/gallery/index.twig', [
            'seo' => $seo,
            'paginator' => $paginator,
            'years' => $this->albums->availableYears(),
            'activeYear' => $year,
        ]);
    }

    public function show(Request $request): Response
    {
        // Conta il numero davanti; il titolo che segue rende leggibile il
        // collegamento e puo cambiare senza spezzare niente.
        $riferimento = (string) $request->route('riferimento');
        $album = $this->albums->findPublished((int) $riferimento);

        if ($album === null) {
            $this->notFound('L album che cerchi non esiste o non è più pubblicato.');
        }

        if ($riferimento !== $album->urlKey()) {
            return RedirectResponse::to($this->url->route('gallery.show', ['riferimento' => $album->urlKey()]), 301);
        }

        $page = $this->page($request);
        $paginator = $this->photos->paginatePublishedForAlbum(
            albumId: $album->id,
            page: $page,
            perPage: 36,
            basePath: $this->url->route('gallery.show', ['riferimento' => $album->urlKey()]),
        );

        $coverUrl = $album->coverPhoto !== null
            ? $this->url->absolute($this->media->url(MediaPaths::COLLECTION_GALLERY, $album->coverPhoto->storageKey, MediaPaths::SIZE_LARGE, 'jpg'))
            : null;

        $seo = $this->seo(
            $album->title,
            sprintf('%s - %s fotografie di %s.', $album->title, $album->photosCount, $this->settings->string('site_group_name')),
        )
            ->withImage($coverUrl)
            ->withCanonical($this->url->absoluteRoute('gallery.show', ['riferimento' => $album->urlKey()]))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Galleria', 'url' => $this->url->route('gallery.index')],
                ['name' => $album->title, 'url' => $this->url->route('gallery.show', ['riferimento' => $album->urlKey()])],
            ]);

        if ($page > 1) {
            $seo->withNoindex();
        }

        return $this->render('site/gallery/show.twig', [
            'seo' => $seo,
            'album' => $album,
            'paginator' => $paginator,
        ]);
    }
}
