<?php

declare(strict_types=1);

/**
 * Rotte dell'area riservata.
 *
 * Struttura in tre livelli di accesso:
 *  - pubbliche: login, recupero password, accettazione invito;
 *  - autenticate: tutto il resto della gestione contenuti;
 *  - super amministratore: account, impostazioni, registro attività.
 *
 * Non esiste alcuna rotta di registrazione: gli account nascono solo da invito.
 */

use App\Controllers\Admin\AdminUserController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\CalendarController;
use App\Controllers\Admin\PanelController;
use App\Controllers\Admin\EventController;
use App\Controllers\Admin\GalleryController;
use App\Controllers\Admin\NewsController;
use App\Controllers\Admin\OrderController;
use App\Controllers\Admin\OrganizationController;
use App\Controllers\Admin\PageController;
use App\Controllers\Admin\ProductController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\SocialController;
use App\Core\Routing\Router;

return static function (Router $router): void {
    // -----------------------------------------------------------------------
    //  Accesso (senza autenticazione)
    // -----------------------------------------------------------------------
    $router->group(['prefix' => '/admin', 'name' => 'admin.'], static function (Router $router): void {
        $router->get('/login', [AuthController::class, 'showLogin'])->name('login');
        $router->post('/login', [AuthController::class, 'login'])->name('login.submit');
        $router->post('/logout', [AuthController::class, 'logout'])->name('logout');

        $router->get('/password-dimenticata', [AuthController::class, 'showForgotPassword'])->name('password.forgot');
        $router->post('/password-dimenticata', [AuthController::class, 'sendResetLink'])->name('password.email');
        $router->get('/reimposta-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset.form');
        $router->post('/reimposta-password/{token}', [AuthController::class, 'resetPassword'])->name('password.reset');

        $router->get('/attiva-account/{token}', [AuthController::class, 'showInviteForm'])->name('invite.accept');
        $router->post('/attiva-account/{token}', [AuthController::class, 'acceptInvite'])->name('invite.submit');
    });

    // -----------------------------------------------------------------------
    //  Area riservata (autenticazione obbligatoria)
    // -----------------------------------------------------------------------
    $router->group(['prefix' => '/admin', 'name' => 'admin.', 'middleware' => 'admin'], static function (Router $router): void {
        $router->get('', [PanelController::class, 'home'])->name('home');

        // --- Profilo personale ---------------------------------------------
        $router->get('/profilo', [AdminUserController::class, 'profile'])->name('profile');
        $router->post('/profilo', [AdminUserController::class, 'updateProfile'])->name('profile.update');
        $router->post('/profilo/password', [AdminUserController::class, 'changePassword'])->name('profile.password');

        // --- Notizie -------------------------------------------------------
        $router->get('/notizie', [NewsController::class, 'index'])->name('news.index');
        $router->get('/notizie/nuova', [NewsController::class, 'create'])->name('news.create');
        $router->post('/notizie', [NewsController::class, 'store'])->name('news.store');
        $router->get('/notizie/{id:\d+}', [NewsController::class, 'edit'])->name('news.edit');
        $router->post('/notizie/{id:\d+}', [NewsController::class, 'update'])->name('news.update');
        $router->post('/notizie/{id:\d+}/elimina', [NewsController::class, 'destroy'])->name('news.destroy');

        // --- Eventi --------------------------------------------------------
        $router->get('/eventi', [EventController::class, 'index'])->name('events.index');
        $router->get('/eventi/nuovo', [EventController::class, 'create'])->name('events.create');
        $router->post('/eventi', [EventController::class, 'store'])->name('events.store');
        $router->get('/eventi/{id:\d+}', [EventController::class, 'edit'])->name('events.edit');
        $router->post('/eventi/{id:\d+}', [EventController::class, 'update'])->name('events.update');
        $router->post('/eventi/{id:\d+}/elimina', [EventController::class, 'destroy'])->name('events.destroy');

        // --- Calendario partite --------------------------------------------
        $router->get('/calendario', [CalendarController::class, 'index'])->name('calendar.index');
        $router->post('/calendario/sincronizza', [CalendarController::class, 'sync'])->name('calendar.sync');

        // --- Galleria ------------------------------------------------------
        $router->get('/galleria', [GalleryController::class, 'index'])->name('gallery.index');
        $router->get('/galleria/nuovo', [GalleryController::class, 'create'])->name('gallery.create');
        $router->post('/galleria', [GalleryController::class, 'store'])->name('gallery.store');
        $router->get('/galleria/{id:\d+}', [GalleryController::class, 'edit'])->name('gallery.edit');
        $router->post('/galleria/{id:\d+}', [GalleryController::class, 'update'])->name('gallery.update');
        $router->post('/galleria/{id:\d+}/elimina', [GalleryController::class, 'destroy'])->name('gallery.destroy');
        $router->post('/galleria/{id:\d+}/carica', [GalleryController::class, 'uploadPhotos'])->name('gallery.upload');
        $router->post('/galleria/{id:\d+}/ordina', [GalleryController::class, 'reorderPhotos'])->name('gallery.reorder');
        $router->post('/galleria/{id:\d+}/rielabora', [GalleryController::class, 'regenerate'])->name('gallery.regenerate');
        $router->post('/galleria/foto/{photoId:\d+}', [GalleryController::class, 'updatePhoto'])->name('gallery.photo.update');
        $router->post('/galleria/foto/{photoId:\d+}/elimina', [GalleryController::class, 'destroyPhoto'])->name('gallery.photo.destroy');
        $router->post('/galleria/foto/{photoId:\d+}/copertina', [GalleryController::class, 'setCover'])->name('gallery.photo.cover');

        // --- Contenuti social ----------------------------------------------
        $router->get('/social', [SocialController::class, 'index'])->name('social.index');
        $router->get('/social/nuovo', [SocialController::class, 'create'])->name('social.create');
        $router->post('/social', [SocialController::class, 'store'])->name('social.store');
        $router->get('/social/{id:\d+}', [SocialController::class, 'edit'])->name('social.edit');
        $router->post('/social/{id:\d+}', [SocialController::class, 'update'])->name('social.update');
        $router->post('/social/{id:\d+}/elimina', [SocialController::class, 'destroy'])->name('social.destroy');
        $router->post('/social/{id:\d+}/visibilita', [SocialController::class, 'toggle'])->name('social.toggle');
        $router->post('/social/sincronizza', [SocialController::class, 'sync'])->name('social.sync');

        // --- Prodotti ------------------------------------------------------
        $router->get('/prodotti', [ProductController::class, 'index'])->name('products.index');
        $router->get('/prodotti/nuovo', [ProductController::class, 'create'])->name('products.create');
        $router->post('/prodotti', [ProductController::class, 'store'])->name('products.store');
        $router->get('/prodotti/{id:\d+}', [ProductController::class, 'edit'])->name('products.edit');
        $router->post('/prodotti/{id:\d+}', [ProductController::class, 'update'])->name('products.update');
        $router->post('/prodotti/{id:\d+}/elimina', [ProductController::class, 'destroy'])->name('products.destroy');
        $router->post('/prodotti/{id:\d+}/immagini/{imageId:\d+}/elimina', [ProductController::class, 'destroyImage'])->name('products.image.destroy');
        $router->post('/prodotti/{id:\d+}/immagini/{imageId:\d+}/principale', [ProductController::class, 'setPrimaryImage'])->name('products.image.primary');

        // --- Ordini --------------------------------------------------------
        $router->get('/ordini', [OrderController::class, 'index'])->name('orders.index');
        $router->get('/ordini/{id:\d+}', [OrderController::class, 'show'])->name('orders.show');
        $router->post('/ordini/{id:\d+}/stato', [OrderController::class, 'updateStatus'])->name('orders.status');
        $router->post('/ordini/{id:\d+}/note', [OrderController::class, 'updateNotes'])->name('orders.notes');
        $router->post('/ordini/{id:\d+}/reinvia', [OrderController::class, 'resendEmail'])->name('orders.resend');
        $router->post('/ordini/{id:\d+}/archivia', [OrderController::class, 'archive'])->name('orders.archive');

        // --- Organizzazione -------------------------------------------------
        $router->get('/organizzazione', [OrganizationController::class, 'index'])->name('organization.index');
        $router->post('/organizzazione/ruoli', [OrganizationController::class, 'storeRole'])->name('organization.roles.store');
        $router->post('/organizzazione/ruoli/{roleId:\d+}', [OrganizationController::class, 'updateRole'])->name('organization.roles.update');
        $router->post('/organizzazione/ruoli/{roleId:\d+}/elimina', [OrganizationController::class, 'destroyRole'])->name('organization.roles.destroy');
        $router->post('/organizzazione/persone', [OrganizationController::class, 'storeMember'])->name('organization.members.store');
        $router->post('/organizzazione/persone/{memberId:\d+}', [OrganizationController::class, 'updateMember'])->name('organization.members.update');
        $router->post('/organizzazione/persone/{memberId:\d+}/elimina', [OrganizationController::class, 'destroyMember'])->name('organization.members.destroy');

        // --- Pagine editoriali ----------------------------------------------
        $router->get('/pagine', [PageController::class, 'index'])->name('pages.index');
        $router->get('/pagine/{id:\d+}', [PageController::class, 'edit'])->name('pages.edit');
        $router->post('/pagine/{id:\d+}', [PageController::class, 'update'])->name('pages.update');
        $router->post('/pagine/{id:\d+}/blocchi', [PageController::class, 'storeBlock'])->name('pages.blocks.store');
        $router->post('/pagine/{id:\d+}/blocchi/{blockId:\d+}', [PageController::class, 'updateBlock'])->name('pages.blocks.update');
        $router->post('/pagine/{id:\d+}/blocchi/{blockId:\d+}/elimina', [PageController::class, 'destroyBlock'])->name('pages.blocks.destroy');
        $router->post('/pagine/{id:\d+}/blocchi/ordina', [PageController::class, 'reorderBlocks'])->name('pages.blocks.reorder');
    });

    // -----------------------------------------------------------------------
    //  Riservato ai super amministratori
    // -----------------------------------------------------------------------
    $router->group([
        'prefix' => '/admin',
        'name' => 'admin.',
        'middleware' => ['admin', 'superadmin'],
    ], static function (Router $router): void {
        $router->get('/amministratori', [AdminUserController::class, 'index'])->name('users.index');
        $router->post('/amministratori/invita', [AdminUserController::class, 'invite'])->name('users.invite');
        $router->post('/amministratori/{id:\d+}/reinvita', [AdminUserController::class, 'resendInvite'])->name('users.resend');
        $router->post('/amministratori/{id:\d+}/blocca', [AdminUserController::class, 'block'])->name('users.block');
        $router->post('/amministratori/{id:\d+}/sblocca', [AdminUserController::class, 'unblock'])->name('users.unblock');
        $router->post('/amministratori/{id:\d+}/ruolo', [AdminUserController::class, 'changeRole'])->name('users.role');
        $router->post('/amministratori/{id:\d+}/elimina', [AdminUserController::class, 'destroy'])->name('users.destroy');

        $router->get('/impostazioni', [SettingsController::class, 'index'])->name('settings.index');
        $router->post('/impostazioni', [SettingsController::class, 'update'])->name('settings.update');

        $router->get('/registro-attivita', [AuditController::class, 'index'])->name('audit.index');
    });
};
