<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\Support\Dates;
use App\Core\View\ViewRenderer;
use App\Services\AuthService;
use App\Services\CalendarService;
use App\Services\Football\FootballService;
use App\Services\SettingsService;
use DateTimeImmutable;

/**
 * Calendario: partite della Fiorentina più appuntamenti del gruppo.
 *
 * Il controller prepara entrambe le viste, griglia mensile ed elenco: il
 * passaggio fra le due avviene lato client in base allo spazio disponibile,
 * senza una seconda richiesta al server.
 */
final class CalendarController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly CalendarService $calendar,
        private readonly FootballService $football,
        private readonly SettingsService $settings,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $period = $this->calendar->normalizeMonth(
            $request->nullableInt('anno'),
            $request->nullableInt('mese'),
        );

        $current = (new DateTimeImmutable())->setDate($period['year'], $period['month'], 1)->setTime(0, 0);

        $seo = $this->seo(
            'Calendario',
            'Partite della Fiorentina e appuntamenti di ' . $this->settings->string('site_group_name') . ': trasferte, riunioni, cene e raduni.',
        )
            ->withCanonical($this->url->absoluteRoute('calendar.index'))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Calendario', 'url' => $this->url->route('calendar.index')],
            ]);

        return $this->render('site/calendar.twig', [
            'seo' => $seo,
            'weeks' => $this->calendar->monthGrid($period['year'], $period['month']),
            'upcoming' => $this->calendar->upcoming(15),
            'currentMonth' => $current,
            'monthLabel' => Dates::monthName($period['month']) . ' ' . $period['year'],
            'previousMonth' => $current->modify('-1 month'),
            'nextMonth' => $current->modify('+1 month'),
            'recentResults' => $this->football->recentResults(5),
            'showTeamLogos' => $this->settings->bool('home_show_team_logos', false),
            'today' => (new DateTimeImmutable())->format('Y-m-d'),
        ]);
    }
}
