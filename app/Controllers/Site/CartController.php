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
use App\Services\SettingsService;
use App\Services\Shop\CartService;

/**
 * Carrello.
 *
 * L'unica funzione che il visitatore ha a disposizione senza account, ed e
 * volutamente così: nessun profilo, nessuna lista dei desideri, nessuno storico.
 * Il carrello vive nella sessione e si chiude con l'invio della richiesta.
 */
final class CartController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly CartService $cart,
        private readonly SettingsService $settings,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function show(Request $request): Response
    {
        $contents = $this->cart->contents();

        foreach ($contents->notices as $notice) {
            $this->warning($notice);
        }

        $seo = $this->seo('Carrello')
            // Il carrello e personale e mutevole: non ha senso indicizzarlo.
            ->withNoindex()
            ->withBreadcrumbs([
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Merchandising', 'url' => $this->url->route('shop.index')],
                ['name' => 'Carrello', 'url' => $this->url->route('cart.show')],
            ]);

        return $this->render('site/shop/cart.twig', [
            'seo' => $seo,
            'cart' => $contents,
            'shopEnabled' => $this->settings->bool('shop_enabled', true),
        ]);
    }

    public function add(Request $request): Response
    {
        $result = $this->cart->add(
            productId: $request->int('product_id'),
            variantId: $request->nullableInt('variant_id'),
            quantity: max(1, $request->int('quantity', 1)),
        );

        if ($request->expectsJson()) {
            return $this->json([
                'ok' => $result->successful,
                'message' => $result->message,
                'count' => $this->cart->itemCount(),
            ], $result->successful ? 200 : 422);
        }

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        // Restando sulla pagina del prodotto l'utente può aggiungere altre
        // taglie senza rifare tutto il percorso.
        return $this->back($request, $this->url->route('cart.show'));
    }

    public function update(Request $request): Response
    {
        $result = $this->cart->updateQuantity(
            lineKey: $request->string('line_key'),
            quantity: $request->int('quantity'),
        );

        if ($request->expectsJson()) {
            return $this->json([
                'ok' => $result->successful,
                'message' => $result->message,
                'count' => $this->cart->itemCount(),
            ], $result->successful ? 200 : 422);
        }

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('cart.show');
    }

    public function remove(Request $request): Response
    {
        $result = $this->cart->remove($request->string('line_key'));

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('cart.show');
    }

    public function clear(Request $request): Response
    {
        $this->cart->clear();
        $this->success('Carrello svuotato.');

        return $this->redirectToRoute('shop.index');
    }
}
