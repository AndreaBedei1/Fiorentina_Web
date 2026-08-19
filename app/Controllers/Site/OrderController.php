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
use App\Services\Shop\ShippingCalculator;
use App\Validation\Validator;

/**
 * Invio della richiesta d'ordine.
 *
 * Nessun pagamento online, in nessuna forma: il modulo raccoglie i dati per la
 * consegna e le note, poi l'ordine viene registrato e le istruzioni di
 * pagamento arrivano via email. Non esiste alcun campo relativo a carte,
 * circuiti o gateway, e non deve essere aggiunto in futuro.
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
        private readonly ShippingCalculator $shipping,
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

        $method = $request->string('consegna', ShippingCalculator::METHOD_DELIVERY);

        if (! $this->shipping->isValidMethod($method)) {
            $method = ShippingCalculator::METHOD_DELIVERY;
        }

        $shippingCost = $this->shipping->costFor($contents->subtotal, $method);

        $seo = $this->seo('Conferma ordine')
            ->withNoindex()
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Merchandising', 'url' => $this->url->route('shop.index')],
                ['name' => 'Carrello', 'url' => $this->url->route('cart.show')],
                ['name' => 'Conferma ordine', 'url' => $this->url->route('order.create')],
            ]);

        return $this->render('site/shop/checkout.twig', [
            'seo' => $seo,
            'cart' => $contents,
            'shippingMethods' => $this->shipping->availableMethods(),
            'selectedMethod' => $method,
            'shippingCost' => $shippingCost,
            'total' => round($contents->subtotal + $shippingCost, 2),
            'paymentInstructions' => $this->settings->string('shop_payment_instructions'),
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

        $method = $request->string('shipping_method', ShippingCalculator::METHOD_DELIVERY);

        if (! $this->shipping->isValidMethod($method)) {
            $method = ShippingCalculator::METHOD_DELIVERY;
        }

        $validator = Validator::make($request->all())
            ->honeypot('website')
            ->required('first_name', 'Il nome')->max('first_name', 80, 'Il nome')
            ->required('last_name', 'Il cognome')->max('last_name', 80, 'Il cognome')
            ->required('email', 'L indirizzo email')->email('email', 'L indirizzo email')->max('email', 190, 'L indirizzo email')
            ->required('phone', 'Il telefono')->phone('phone', 'Il telefono')
            ->optional('notes')->max('notes', 1000, 'Le note')
            ->in('shipping_method', array_keys($this->shipping->availableMethods()), 'Il metodo di consegna');

        // L'indirizzo serve solo se il pacco va spedito: per il ritiro in sede
        // chiederlo sarebbe un ostacolo inutile.
        if ($method === ShippingCalculator::METHOD_DELIVERY) {
            $validator
                ->required('address', 'L indirizzo')->max('address', 255, 'L indirizzo')
                ->required('postal_code', 'Il CAP')->postalCode('postal_code', 'Il CAP')
                ->required('city', 'La citta')->max('city', 100, 'La citta')
                ->required('province', 'La provincia')->province('province', 'La provincia');
        } else {
            $validator->optional('address')->optional('postal_code')->optional('city')->optional('province');
        }

        if ($validator->fails()) {
            return $this->backWithErrors(
                $request,
                $request->all(),
                $validator->errors(),
                $this->url->route('order.create'),
            );
        }

        $data = $validator->validatedData();
        $data['shipping_method'] = $method;

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
