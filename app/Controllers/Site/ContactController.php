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
use App\Repositories\PageRepository;
use App\Services\AuthService;
use App\Services\Mail\MailService;
use App\Services\RateLimiter;
use App\Services\SettingsService;
use App\Validation\Validator;

/**
 * Pagina contatti e relativo modulo.
 *
 * Quello che viene scritto qui esce dal sito e basta: arriva per email
 * all'indirizzo del gruppo e non viene conservato da nessuna parte. Il
 * pannello non ha una casella di posta, e non deve averla: una copia in piu
 * di dati personali va custodita, cancellata quando serve e protetta, e per
 * leggere un messaggio l'email basta e avanza.
 *
 * Difese antispam a strati, nessuna delle quali chiede nulla al visitatore:
 * token CSRF, campo trappola invisibile, controllo del tempo di compilazione e
 * limite di invii per indirizzo IP. Un captcha si aggiungerebbe solo se lo
 * spam diventasse un problema reale: nel frattempo penalizzerebbe soprattutto
 * chi naviga con tecnologie assistive.
 */
final class ContactController extends Controller
{
    /** Un modulo compilato in meno di tre secondi non e stato compilato da una persona. */
    private const MIN_FILL_SECONDS = 3;

    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly PageRepository $pages,
        private readonly MailService $mail,
        private readonly SettingsService $settings,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function show(Request $request): Response
    {
        $page = $this->pages->findBySlug(Page::SLUG_CONTATTI);

        $seo = $this->seo(
            $page?->seoTitle() ?? 'Contatti',
            $page?->seoDescription() ?? 'Scrivici: informazioni su iscrizioni, trasferte, merchandising ed eventi.',
        )
            ->withCanonical($this->url->absoluteRoute('contact.show'))
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Contatti', 'url' => $this->url->route('contact.show')],
            ]);

        return $this->render('site/pages/contact.twig', [
            'seo' => $seo,
            'page' => $page,
            'formStartedAt' => time(),
        ]);
    }

    public function send(Request $request): Response
    {
        $maxAttempts = $this->config->int('security.rate_limits.contact.max_attempts', 5);
        $decay = $this->config->int('security.rate_limits.contact.decay_minutes', 60);

        if ($this->limiter->tooManyAttempts('contact', $request->ip(), $maxAttempts)) {
            $this->error('Hai già inviato diversi messaggi. Attendi qualche minuto oppure scrivici direttamente via email.');

            return $this->redirectToRoute('contact.show');
        }

        $validator = Validator::make($request->all())
            ->honeypot('website')
            ->required('name', 'Il nome')->max('name', 120, 'Il nome')
            ->required('email', 'L\'indirizzo email')->email('email', 'L\'indirizzo email')->max('email', 190, 'L\'indirizzo email')
            ->required('subject', 'L oggetto')->max('subject', 200, 'L oggetto')
            ->required('message', 'Il messaggio')->max('message', 5000, 'Il messaggio');

        // Controllo del tempo di compilazione: i bot inviano quasi istantaneamente.
        $startedAt = $request->int('form_started_at');

        if ($startedAt > 0 && (time() - $startedAt) < self::MIN_FILL_SECONDS) {
            $validator->addError('message', 'Invio troppo rapido: riprova fra qualche secondo.');
        }

        $this->limiter->hit('contact', $request->ip(), $decay);

        if ($validator->fails()) {
            return $this->backWithErrors(
                $request,
                $request->all(),
                $validator->errors(),
                $this->url->route('contact.show'),
            );
        }

        $data = $validator->validatedData();
        $destinatario = $this->settings->string('contact_email', $this->config->string('mail.to.contact'));

        // Il sito non tiene copia di quello che gli viene scritto: l'email e
        // l'unico posto in cui il messaggio esiste. Per questo il suo esito
        // conta, e va detto: annunciare "inviato" quando la posta non e
        // partita lascerebbe qualcuno ad aspettare una risposta che nessuno
        // ha mai letto.
        $inviato = $this->mail->send(
            $destinatario,
            'Nuovo messaggio dal sito: ' . $data['subject'],
            'emails/contact-message.twig',
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'ip' => $request->ip(),
            ],
            replyTo: $data['email'],
        );

        if (! $inviato) {
            // L'indirizzo si suggerisce solo se e davvero un indirizzo: se la
            // posta non parte proprio perche quel campo e configurato male,
            // ripeterlo a chi legge non lo aiuterebbe.
            $this->error(filter_var($destinatario, FILTER_VALIDATE_EMAIL) !== false
                ? sprintf(
                    'Non siamo riusciti a inoltrare il messaggio. Riprova fra qualche minuto oppure scrivici direttamente a %s.',
                    $destinatario,
                )
                : 'Non siamo riusciti a inoltrare il messaggio. Riprova fra qualche minuto o contattaci dai profili social.');

            // Il testo resta nel modulo: nessuno deve riscrivere tutto.
            $this->session->flashInput($request->all());

            return $this->redirectToRoute('contact.show');
        }

        $this->success('Messaggio inviato. Ti risponderemo appena possibile.');

        return $this->redirectToRoute('contact.show');
    }
}
