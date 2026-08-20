<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\Support\Dates;
use App\Core\View\ViewRenderer;
use App\Models\FootballMatch;
use App\Repositories\FootballMatchRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Football\FootballService;
use App\Services\SettingsService;
use App\Validation\Validator;

/**
 * Calendario partite dal pannello.
 *
 * Le partite arrivano dall API tramite cron, ma l amministratore può
 * aggiungerne o correggerne a mano: amichevoli, recuperi, orari cambiati
 * all ultimo. Una partita marcata come manuale non viene più sovrascritta
 * dalla sincronizzazione, altrimenti la correzione durerebbe fino al cron
 * successivo.
 */
final class CalendarController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly FootballMatchRepository $matches,
        private readonly FootballService $football,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('calendar.manage');

        return $this->render('admin/calendar/index.twig', [
            'seo' => $this->seo('Calendario partite')->withNoindex(),
            'paginator' => $this->matches->paginateForAdmin(
                page: $this->page($request),
                perPage: 25,
                basePath: $this->url->route('admin.calendar.index'),
            ),
            'provider' => $this->football->providerName(),
            'lastSync' => $this->football->lastSyncedAt(),
            'isStale' => $this->football->isStale(),
            'statuses' => $this->statusOptions(),
            'teamName' => $this->config->string('services.football.team_name', 'Fiorentina'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('calendar.manage');

        return $this->render('admin/calendar/form.twig', [
            'seo' => $this->seo('Nuova partita')->withNoindex(),
            'match' => null,
            'statuses' => $this->statusOptions(),
            'teamName' => $this->config->string('services.football.team_name', 'Fiorentina'),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('calendar.manage');

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.calendar.create'));
        }

        $id = $this->matches->createManual($this->buildData($request, $validated));

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'match',
            $id,
            sprintf('Partita inserita a mano: %s', $validated['home_team'] . ' - ' . $validated['away_team']),
        );

        $this->success('Partita aggiunta al calendario.');

        return $this->redirectToRoute('admin.calendar.index');
    }

    public function edit(Request $request): Response
    {
        $this->authorize('calendar.manage');

        $match = $this->matches->find($request->routeInt('id'));

        if ($match === null) {
            $this->notFound('Partita non trovata.');
        }

        return $this->render('admin/calendar/form.twig', [
            'seo' => $this->seo('Modifica partita')->withNoindex(),
            'match' => $match,
            'statuses' => $this->statusOptions(),
            'teamName' => $this->config->string('services.football.team_name', 'Fiorentina'),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('calendar.manage');

        $id = $request->routeInt('id');

        if ($this->matches->find($id) === null) {
            $this->notFound('Partita non trovata.');
        }

        $validated = $this->validateInput($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.calendar.edit', ['id' => $id]));
        }

        $data = $this->buildData($request, $validated);
        // Una modifica manuale mette la partita al riparo dal prossimo sync.
        $data['is_manual'] = 1;

        $this->matches->update($id, $data);

        $this->audit->log(AuditLogger::CONTENT_UPDATED, 'match', $id, 'Partita aggiornata a mano');
        $this->success('Partita aggiornata. Non verrà più sovrascritta dalla sincronizzazione automatica.');

        return $this->redirectToRoute('admin.calendar.index');
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('calendar.manage');

        $this->matches->delete($request->routeInt('id'));
        $this->success('Partita rimossa dal calendario.');

        return $this->redirectToRoute('admin.calendar.index');
    }

    /** Sincronizzazione immediata, senza attendere il cron. */
    public function sync(Request $request): Response
    {
        $this->authorize('calendar.manage');

        $report = $this->football->sync();

        $report->hasErrors()
            ? $this->warning($report->summary())
            : $this->success($report->summary());

        return $this->redirectToRoute('admin.calendar.index');
    }

    /** @return array<string, mixed>|null */
    private function validateInput(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('home_team', 'La squadra di casa')->max('home_team', 100, 'La squadra di casa')
            ->required('away_team', 'La squadra ospite')->max('away_team', 100, 'La squadra ospite')
            ->required('competition', 'La competizione')->max('competition', 100, 'La competizione')
            ->optional('round_label')->max('round_label', 60, 'La giornata')
            ->optional('venue')->max('venue', 150, 'Lo stadio')
            ->date('kickoff_date', 'La data', required: true)
            ->time('kickoff_time', 'L orario')
            ->in('status', array_keys($this->statusOptions()), 'Lo stato')
            ->integer('home_score', 'Il punteggio della squadra di casa', 0, 99)
            ->integer('away_score', 'Il punteggio della squadra ospite', 0, 99);

        if ($validator->fails()) {
            $this->session->flashInput($request->all());
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return null;
        }

        return $validator->validatedData();
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function buildData(Request $request, array $validated): array
    {
        $teamName = mb_strtolower($this->config->string('services.football.team_name', 'Fiorentina'));
        $homeTeam = (string) $validated['home_team'];
        $awayTeam = (string) $validated['away_team'];
        $isHome = mb_strtolower(trim($homeTeam)) === $teamName;

        return [
            'competition' => (string) $validated['competition'],
            'round_label' => $validated['round_label'] ?? null,
            'season' => (int) date('Y'),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'is_home' => $isHome ? 1 : 0,
            'opponent' => $isHome ? $awayTeam : $homeTeam,
            'venue' => $validated['venue'] ?? null,
            'kickoff_at' => Dates::combineToDatabase($validated['kickoff_date'] ?? null, $validated['kickoff_time'] ?? null),
            // Un orario scritto a mano e per definizione quello giusto: chi
            // compila il modulo sa a che ora si gioca.
            'kickoff_time_confirmed' => 1,
            'status' => (string) ($validated['status'] ?? FootballMatch::STATUS_SCHEDULED),
            'home_score' => $validated['home_score'] ?? null,
            'away_score' => $validated['away_score'] ?? null,
        ];
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            FootballMatch::STATUS_SCHEDULED => 'In programma',
            FootballMatch::STATUS_LIVE => 'In corso',
            FootballMatch::STATUS_FINISHED => 'Terminata',
            FootballMatch::STATUS_POSTPONED => 'Rinviata',
            FootballMatch::STATUS_CANCELLED => 'Annullata',
        ];
    }
}
