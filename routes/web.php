<?php

declare(strict_types=1);

/**
 * Rotte pubbliche.
 *
 * Gli indirizzi sono in italiano e descrittivi: sono parte dell'identita del
 * sito e contano per il posizionamento. Cambiarli in futuro significa rompere i
 * link gia condivisi, quindi vanno scelti una volta e mantenuti.
 */

use App\Controllers\Site\CalendarController;
use App\Controllers\Site\CartController;
use App\Controllers\Site\ContactController;
use App\Controllers\Site\EventController;
use App\Controllers\Site\GalleryController;
use App\Controllers\Site\HomeController;
use App\Controllers\Site\NewsController;
use App\Controllers\Site\OrderController;
use App\Controllers\Site\PageController;
use App\Controllers\Site\ShopController;
use App\Controllers\Site\SitemapController;
use App\Core\Routing\Router;

return static function (Router $router): void {
    // --- Home -------------------------------------------------------------
    $router->get('/', [HomeController::class, 'index'])->name('home');

    // --- Pagine editoriali -------------------------------------------------
    $router->get('/chi-siamo', [PageController::class, 'about'])->name('page.about');
    $router->get('/diventa-socio', [PageController::class, 'join'])->name('page.join');
    $router->get('/privacy', [PageController::class, 'privacy'])->name('page.privacy');
    $router->get('/cookie-policy', [PageController::class, 'cookies'])->name('page.cookies');

    // --- Notizie -----------------------------------------------------------
    $router->get('/notizie', [NewsController::class, 'index'])->name('news.index');
    $router->get('/notizie/{slug}', [NewsController::class, 'show'])->name('news.show');

    // --- Eventi ------------------------------------------------------------
    $router->get('/eventi', [EventController::class, 'index'])->name('events.index');
    $router->get('/eventi/{slug}', [EventController::class, 'show'])->name('events.show');

    // --- Calendario --------------------------------------------------------
    $router->get('/calendario', [CalendarController::class, 'index'])->name('calendar.index');

    // --- Galleria ----------------------------------------------------------
    $router->get('/galleria', [GalleryController::class, 'index'])->name('gallery.index');
    $router->get('/galleria/{slug}', [GalleryController::class, 'show'])->name('gallery.show');

    // --- Merchandising -----------------------------------------------------
    $router->get('/merchandising', [ShopController::class, 'index'])->name('shop.index');
    $router->get('/merchandising/{slug}', [ShopController::class, 'show'])->name('shop.show');

    // --- Carrello ----------------------------------------------------------
    $router->get('/carrello', [CartController::class, 'show'])->name('cart.show');
    $router->post('/carrello/aggiungi', [CartController::class, 'add'])->name('cart.add');
    $router->post('/carrello/aggiorna', [CartController::class, 'update'])->name('cart.update');
    $router->post('/carrello/rimuovi', [CartController::class, 'remove'])->name('cart.remove');
    $router->post('/carrello/svuota', [CartController::class, 'clear'])->name('cart.clear');

    // --- Ordine (nessun pagamento online: solo richiesta) ------------------
    $router->get('/ordine', [OrderController::class, 'create'])->name('order.create');
    $router->post('/ordine', [OrderController::class, 'store'])->name('order.store');
    $router->get('/ordine/confermato', [OrderController::class, 'confirmation'])->name('order.confirmation');

    // --- Contatti ----------------------------------------------------------
    $router->get('/contatti', [ContactController::class, 'show'])->name('contact.show');
    $router->post('/contatti', [ContactController::class, 'send'])->name('contact.send');

    // --- File tecnici ------------------------------------------------------
    $router->get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
    $router->get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
};
