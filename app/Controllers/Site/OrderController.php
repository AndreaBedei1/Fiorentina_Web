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
use App\Services\AuthService;
use App\Services\RateLimiter;
use App\Services\SettingsService;
use App\Services\Shop\CartService;
use App\Services\Shop\OrderService;
use App\Validation\Validator;

/**
 * Invio dell'ordine.
 *
 * Nessun pagamento online, in nessuna forma: il modulo raccoglie il nome, i
 * recapiti e l'indirizzo, poi l'ordine viene registrato, il responsabile
 * merchandising riceve la notifica e ricontatta il cliente per concordare
 * pagamento e spedizione. Non esiste alcun campo relativo a carte, circuiti o
 * gateway, e non deve essere aggiunto in futuro.
 *
 * Tutti i campi sono obbligatori: senza recapito non si puo richiamare
 * nessuno, e senza indirizzo completo non si puo spedire niente.
 */
final class OrderController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly CartService $cart,
        private readonly OrderService $orders,
        private readonly SettingsService $settings,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    /** Modulo di riepilogo e dati del cliente. */
    public function create(Request $request): Response
    {
        $contents = $this->cart->contents();

        if ($contents->isEmpty()) {
            $this->info('Il carrello e vuoto: aggiungi qualcosa prima di procedere.');

            return $this->redirectToRoute('shop.index');
        }

        if (! $this->settings->bool('shop_enabled', true)) {
            $this->warning('Gli ordini sono temporaneamente sospesi. Scrivici per informazioni.');

            return $this->redirectToRoute('cart.show');
        }

        $seo = $this->seo('Invia ordine')
            ->withNoindex()
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Merchandising', 'url' => $this->url->route('shop.index')],
                ['name' => 'Carrello', 'url' => $this->url->route('cart.show')],
                ['name' => 'Invia ordine', 'url' => $this->url->route('order.create')],
            ]);

        return $this->render('site/shop/checkout.twig', [
            'seo' => $seo,
            'cart' => $contents,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($this->cart->isEmpty()) {
            $this->info('Il carrello e vuoto.');

            return $this->redirectToRoute('shop.index');
        }

        // Freno agli invii ripetuti dallo stesso indirizzo: senza, un modulo
        // pubblico diventa un canale comodo per riempire il database.
        $maxAttempts = $this->config->int('security.rate_limits.order.max_attempts', 10);
        $decay = $this->config->int('security.rate_limits.order.decay_minutes', 60);

        if ($this->limiter->tooManyAttempts('order', $request->ip(), $maxAttempts)) {
            $this->error('Hai inviato troppe richieste ravvicinate. Riprova fra qualche minuto o scrivici via email.');

            return $this->redirectToRoute('cart.show');
        }

        // Ogni campo e obbligatorio: il gruppo spedisce e basta, quindi senza
        // indirizzo completo l'ordine non e evadibile, e senza telefono ed
        // email non si puo richiamare nessuno per il pagamento.
        $validator = Validator::make($request->all())
            ->honeypot('website')
            ->required('first_name', 'Il nome')->max('first_name', 80, 'Il nome')
            ->required('last_name', 'Il cognome')->max('last_name', 80, 'Il cognome')
            ->required('email', "L'indirizzo email")->email('email', "L'indirizzo email")->max('email', 190, "L'indirizzo email")
            ->required('phone', 'Il telefono')->phone('phone', 'Il telefono')
            ->required('address', "L'indirizzo")->max('address', 255, "L'indirizzo")
            ->required('postal_code', 'Il CAP')->postalCode('postal_code', 'Il CAP')
            ->required('city', 'La città')->max('city', 100, 'La città')
            ->required('province', 'La provincia')->province('province', 'La provincia');

        if ($validator->fails()) {
            return $this->backWithErrors(
                $request,
                $request->all(),
                $validator->errors(),
                $this->url->route('order.create'),
            );
        }

        $data = $validator->validatedData();

        $this->limiter->hit('order', $request->ip(), $decay);

        $result = $this->orders->placeOrder($data, $request);

        if ($result->failed()) {
            $this->error((string) $result->error);

            return $this->redirectToRoute('cart.show');
        }

        // Il numero d'ordine resta in sessione per un solo passaggio: consente
        // di mostrare la pagina di conferma senza esporre l'ordine a un URL
        // indovinabile da chiunque.
        $this->session->put('last_order_number', $result->order?->orderNumber);
        $this->session->put('last_order_notified', $result->customerNotified);

        return $this->redirectToRoute('order.confirmation');
    }

    public function confirmation(Request $request): Response
    {
        $orderNumber = $this->session->get('last_order_number');

        if (! is_string($orderNumber) || $orderNumber === '') {
            return $this->redirectToRoute('shop.index');
        }

        $notified = (bool) $this->session->get('last_order_notified', false);

        $this->session->forget('last_order_number');
        $this->session->forget('last_order_notified');

        return $this->render('site/shop/confirmation.twig', [
            'seo' => $this->seo('Ordine ricevuto')->withNoindex(),
            'orderNumber' => $orderNumber,
            'customerNotified' => $notified,
            'paymentInstructions' => $this->settings->string('shop_payment_instructions'),
            'contactEmail' => $this->settings->string('contact_merchandising_email'),
        ]);
    }
}
