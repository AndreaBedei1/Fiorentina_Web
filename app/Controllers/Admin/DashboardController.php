<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Repositories\AlbumRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ContactMessageRepository;
use App\Repositories\EventRepository;
use App\Repositories\NewsRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\Football\FootballService;
use App\Services\Media\ImageProcessor;
use App\Services\Media\WatermarkService;
use App\Services\SettingsService;
use App\Services\Social\SocialService;

/**
 * Pannello iniziale dell'area riservata.
 *
 * Pensata per persone non tecniche: prima cio che richiede attenzione (ordini
 * nuovi, messaggi da leggere, bozze in sospeso), poi i numeri complessivi. Gli
 * avvisi di configurazione compaiono solo quando c'e davvero qualcosa da
 * sistemare, altrimenti diventano rumore che si impara a ignorare.
 */
final class DashboardController extends Controller
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
        private readonly OrderRepository $orders,
        private readonly ContactMessageRepository $messages,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLogs,
        private readonly FootballService $football,
        private readonly SocialService $social,
        private readonly SettingsService $settings,
        private readonly ImageProcessor $images,
        private readonly WatermarkService $watermark,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $user = $this->currentUser();

        $orderStats = $this->orders->statistics();
        $newsStats = $this->news->statistics();
        $eventStats = $this->events->statistics();
        $galleryStats = $this->albums->statistics();
        $productStats = $this->products->statistics();

        return $this->render('admin/dashboard.twig', [
            'seo' => $this->seo('Pannello di controllo')->withNoindex(),
            'user' => $user,
            'stats' => [
                'orders' => $orderStats,
                'news' => $newsStats,
                'events' => $eventStats,
                'gallery' => $galleryStats,
                'products' => $productStats,
                'messages' => $this->messages->countNew(),
                'users' => $this->auth->isSuperAdmin() ? $this->users->statistics() : null,
            ],
            'recentOrders' => $this->orders->recent(5),
            'latestNews' => $this->news->latestPublished(4),
            'upcomingEvents' => $this->events->upcoming(4),
            'nextMatch' => $this->football->nextMatch(),
            'recentActivity' => $this->auth->isSuperAdmin() ? $this->auditLogs->latest(8) : [],
            'warnings' => $this->collectWarnings(),
            'systemInfo' => [
                'imageDriver' => $this->safeDriverName(),
                'watermarkReady' => $this->watermark->isAvailable(),
                'footballProvider' => $this->football->providerName(),
                'footballLastSync' => $this->football->lastSyncedAt(),
                'socialMock' => $this->social->isMockMode(),
                'socialLastSync' => $this->social->lastSyncedAt(),
                'mailer' => $this->config->string('mail.mailer'),
            ],
        ]);
    }

    /**
     * Avvisi di configurazione.
     *
     * Ognuno indica un'azione concreta: niente diagnostica fine a se stessa.
     *
     * @return list<array{level: string, message: string, action?: string, url?: string}>
     */
    private function collectWarnings(): array
    {
        $warnings = [];

        if ($this->messages->countNew() > 0) {
            $warnings[] = [
                'level' => 'info',
                'message' => sprintf(
                    '%d %s da leggere nel modulo contatti.',
                    $this->messages->countNew(),
                    $this->messages->countNew() === 1 ? 'messaggio' : 'messaggi',
                ),
                'action' => 'Vai ai messaggi',
                'url' => $this->url->route('admin.messages.index'),
            ];
        }

        $newOrders = $this->orders->statistics()['new'];

        if ($newOrders > 0) {
            $warnings[] = [
                'level' => 'attention',
                'message' => sprintf(
                    '%d %s in attesa di essere presi in carico.',
                    $newOrders,
                    $newOrders === 1 ? 'ordine' : 'ordini',
                ),
                'action' => 'Vai agli ordini',
                'url' => $this->url->route('admin.orders.index'),
            ];
        }

        // Testi con segnaposto ancora da compilare: meglio accorgersene qui che
        // scoprirli pubblicati sul sito.
        $placeholders = [];

        foreach (['shop_payment_instructions', 'contact_address', 'footer_association_details', 'membership_fee'] as $key) {
            if (str_contains(mb_strtoupper($this->settings->string($key)), 'DA COMPLETARE')
                || str_contains(mb_strtoupper($this->settings->string($key)), 'DA CONFERMARE')
            ) {
                $placeholders[] = $key;
            }
        }

        if ($placeholders !== [] && $this->auth->isSuperAdmin()) {
            $warnings[] = [
                'level' => 'warning',
                'message' => sprintf(
                    '%d %s contengono ancora testi segnaposto da sostituire.',
                    count($placeholders),
                    count($placeholders) === 1 ? 'impostazione' : 'impostazioni',
                ),
                'action' => 'Vai alle impostazioni',
                'url' => $this->url->route('admin.settings.index'),
            ];
        }

        if (! $this->watermark->isAvailable() && $this->watermark->isEnabled()) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'La filigrana e attiva ma il file del logo non e stato trovato: le fotografie vengono pubblicate senza.',
            ];
        }

        if ($this->config->string('app.env') === 'production' && $this->config->bool('app.debug')) {
            $warnings[] = [
                'level' => 'danger',
                'message' => 'La modalita debug e attiva in produzione: disattiva APP_DEBUG nel file .env.',
            ];
        }

        if ($this->config->string('mail.mailer') === 'log') {
            $warnings[] = [
                'level' => 'info',
                'message' => 'Le email non vengono spedite davvero: sono salvate come file in storage/logs/mail (modalita di sviluppo).',
            ];
        }

        return $warnings;
    }

    /** Il driver immagini non deve poter far fallire la dashboard. */
    private function safeDriverName(): string
    {
        try {
            return $this->images->driverName();
        } catch (\Throwable) {
            return 'non disponibile';
        }
    }
}
