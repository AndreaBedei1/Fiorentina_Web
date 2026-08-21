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
use App\Services\AuthService;
use App\Services\Football\FootballService;

/**
 * Calendario partite dal pannello.
 *
 * Le partite arrivano dal fornitore tramite cron e si leggono sul sito: qui
 * non c'e niente da amministrare. L'unica cosa che questa pagina offre e
 * chiedere di riallineare il calendario adesso, senza aspettare la notte.
 */
final class CalendarController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly FootballService $football,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('calendar.manage');

        return $this->render('admin/calendar/index.twig', [
            'seo' => $this->seo('Calendario partite')->withNoindex(),
        ]);
    }

    /** Sincronizzazione immediata, senza attendere il cron. */
    public function sync(Request $request): Response
    {
        $this->authorize('calendar.manage');

        $report = $this->football->sync();
        $scritte = $report->upcomingCount + $report->resultsCount;

        /*
         * Chi preme il pulsante vuole sapere una cosa sola: il calendario
         * adesso e giusto o no. Il dettaglio - fornitore, numeri, messaggio
         * del server - resta nei log e nel comando di cron, che li ha
         * qualcuno che sa leggerli.
         *
         * I tre casi sono distinti perche dire "fallita" quando meta delle
         * partite sono arrivate, o "aggiornato" quando non e arrivato niente,
         * sarebbe falso in due modi diversi.
         */
        if (! $report->hasErrors()) {
            $this->success('Calendario aggiornato.');
        } elseif ($scritte > 0) {
            $this->warning('Calendario aggiornato solo in parte: alcune partite non sono arrivate. Riprova fra qualche minuto.');
        } else {
            $this->error('Sincronizzazione fallita. Riprova fra qualche minuto.');
        }

        return $this->redirectToRoute('admin.calendar.index');
    }
}
