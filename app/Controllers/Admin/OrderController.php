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
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Shop\OrderService;

/**
 * Gestione degli ordini ricevuti.
 *
 * Gli ordini non si eliminano: si archiviano. Un ordine sparito per un clic
 * sbagliato significherebbe un socio che ha pagato e nessuna traccia di cosa
 * avesse chiesto.
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
        private readonly AuditLogger $audit,
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
            'history' => $this->orders->statusHistory($order->id),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        $this->authorize('orders.manage');

        $id = $request->routeInt('id');
        $status = $request->string('status');

        if (! in_array($status, Order::allStatuses(), true)) {
            $this->error('Stato non valido.');

            return $this->redirectToRoute('admin.orders.show', ['id' => $id]);
        }

        $updated = $this->orderService->updateStatus(
            $id,
            $status,
            $this->currentUser(),
            $request->nullableString('note'),
        );

        $updated
            ? $this->success('Stato aggiornato a: ' . Order::statusLabelFor($status))
            : $this->error('Non e stato possibile aggiornare lo stato.');

        return $this->redirectToRoute('admin.orders.show', ['id' => $id]);
    }

    public function updateNotes(Request $request): Response
    {
        $this->authorize('orders.manage');

        $id = $request->routeInt('id');
        $this->orders->updateAdminNotes($id, $request->nullableString('admin_notes'));
        $this->success('Note interne salvate.');

        return $this->redirectToRoute('admin.orders.show', ['id' => $id]);
    }

    public function resendEmail(Request $request): Response
    {
        $this->authorize('orders.manage');

        $id = $request->routeInt('id');

        $this->orderService->resendCustomerEmail($id)
            ? $this->success('Email di riepilogo inviata nuovamente al cliente.')
            : $this->error('Invio non riuscito. Controlla la configurazione della posta.');

        return $this->redirectToRoute('admin.orders.show', ['id' => $id]);
    }

    public function archive(Request $request): Response
    {
        $this->authorize('orders.manage');

        $id = $request->routeInt('id');
        $order = $this->orders->find($id, withItems: false);

        if ($order === null) {
            $this->notFound('Ordine non trovato.');
        }

        $this->orders->delete($id);

        $this->audit->log(
            AuditLogger::ORDER_ARCHIVED,
            'order',
            $id,
            sprintf('Ordine %s archiviato', $order->orderNumber),
        );

        $this->success('Ordine archiviato. Resta nel database e puo essere recuperato.');

        return $this->redirectToRoute('admin.orders.index');
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        $options = [];

        foreach (Order::allStatuses() as $status) {
            $options[$status] = Order::statusLabelFor($status);
        }

        return $options;
    }
}
