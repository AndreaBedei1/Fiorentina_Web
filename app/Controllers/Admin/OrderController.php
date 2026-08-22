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
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\Shop\OrderService;

/**
 * Gestione degli ordini ricevuti.
 *
 * Si guardano e si segnano come completati: nient'altro. Non si eliminano -
 * un ordine sparito per un clic sbagliato significherebbe un socio che ha
 * pagato e nessuna traccia di cosa avesse chiesto - e non si scrivono note,
 * perche chi segue un ordine lo segue al telefono.
 */
final class OrderController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly OrderRepository $orders,
        private readonly OrderService $orderService,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('orders.manage');

        $status = $request->string('stato');

        return $this->render('admin/orders/index.twig', [
            'seo' => $this->seo('Ordini')->withNoindex(),
            'paginator' => $this->orders->paginateForAdmin(
                page: $this->page($request),
                perPage: 20,
                status: $status !== '' ? $status : null,
                search: $request->string('q'),
                basePath: $this->url->route('admin.orders.index'),
            ),
            'activeStatus' => $status,
            'search' => $request->string('q'),
            'statuses' => $this->statusOptions(),
            'counts' => $this->orders->countsByStatus(),
        ]);
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.manage');

        $order = $this->orders->find($request->routeInt('id'));

        if ($order === null) {
            $this->notFound('Ordine non trovato.');
        }

        return $this->render('admin/orders/show.twig', [
            'seo' => $this->seo('Ordine ' . $order->orderNumber)->withNoindex(),
            'order' => $order,
        ]);
    }

    /**
     * Segna l'ordine come completato, o lo riporta fra quelli da gestire.
     *
     * Il modulo dice in quale dei due versi si sta andando: cosi il pulsante
     * resta uno, e premerlo due volte non fa avanti e indietro a sorpresa.
     */
    public function setCompleted(Request $request): Response
    {
        $this->authorize('orders.manage');

        $id = $request->routeInt('id');
        $completato = $request->bool('completato');

        $this->orderService->setCompleted($id, $completato, $this->currentUser())
            ? $this->success($completato
                ? 'Ordine segnato come completato.'
                : 'Ordine riportato fra quelli da gestire.')
            : $this->error('Non è stato possibile aggiornare l\'ordine.');

        return $this->redirectToRoute('admin.orders.show', ['id' => $id]);
    }

    /**
     * Le due caselle dei filtri rapidi.
     *
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (Order::allStatuses() as $status) {
            $options[$status] = Order::statusLabelFor($status);
        }

        return $options;
    }
}
