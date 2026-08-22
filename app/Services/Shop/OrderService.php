<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\Config;
use App\Core\Http\Request;
use App\Models\Order;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Services\Mail\MailService;
use App\Services\SettingsService;

/**
 * Creazione e gestione delle richieste d'ordine.
 *
 * Il sito non incassa: registra la richiesta, avvisa il responsabile
 * merchandising e manda al cliente le istruzioni per il pagamento offline.
 * Nessun dato di pagamento viene raccolto, in nessun punto del flusso.
 *
 * L'ordine viene salvato prima di tentare gli invii: se l'email non parte,
 * l'ordine esiste comunque ed e visibile nel pannello. Il contrario, cioè
 * perdere un ordine perché il server di posta non risponde, sarebbe grave.
 */
final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CartService $cart,
        private readonly MailService $mail,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * Trasforma il carrello in un ordine registrato.
     *
     * @param array<string, mixed> $customer Dati già validati del cliente.
     */
    public function placeOrder(array $customer, Request $request): OrderPlacementResult
    {
        if (! $this->settings->bool('shop_enabled', true)) {
            return OrderPlacementResult::failure(
                'Gli ordini sono temporaneamente sospesi. Scrivici per informazioni.'
            );
        }

        $contents = $this->cart->contents();

        if ($contents->isEmpty()) {
            return OrderPlacementResult::failure('Il carrello e vuoto.');
        }

        // I totali si ricalcolano qui, lato server, a partire dai prezzi
        // correnti: nulla di cio che arriva dal browser entra nell'importo.
        // La spedizione non c'entra: non ha un costo noto adesso e viene
        // concordata dopo, quando si chiama il cliente per il pagamento.
        $total = $contents->subtotal;

        $items = [];

        foreach ($contents->lines as $line) {
            $items[] = [
                'product_id' => $line->product->id,
                'variant_id' => $line->variant?->id,
                'product_name' => $line->product->name,
                'variant_option' => $line->variant !== null ? 'Taglia' : null,
                'variant_label' => $line->variant?->label,
                'image_key' => $line->imageKey(),
                'unit_price' => $line->unitPrice,
                'quantity' => $line->quantity,
                'line_total' => $line->lineTotal,
            ];
        }

        $orderId = $this->orders->createWithItems([
            'status' => Order::STATUS_NEW,
            'customer_first_name' => (string) $customer['first_name'],
            'customer_last_name' => (string) $customer['last_name'],
            'customer_email' => (string) $customer['email'],
            'customer_phone' => (string) $customer['phone'],
            'shipping_address' => (string) ($customer['address'] ?? ''),
            'shipping_postal_code' => (string) ($customer['postal_code'] ?? ''),
            'shipping_city' => (string) ($customer['city'] ?? ''),
            'shipping_province' => (string) ($customer['province'] ?? ''),
            'shipping_country' => 'IT',
            'subtotal' => $total,
            'total' => $total,
            'items_count' => $contents->totalQuantity,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $items);

        $order = $this->orders->find($orderId);

        if ($order === null) {
            return OrderPlacementResult::failure('Si e verificato un errore nella registrazione dell ordine.');
        }

        $this->cart->clear();

        $notifications = $this->sendNotifications($order);
        $this->orders->markNotified($orderId, $notifications['manager'], $notifications['customer']);

        return OrderPlacementResult::success($order, $notifications['customer']);
    }

    /** @return array{manager: bool, customer: bool} */
    private function sendNotifications(Order $order): array
    {
        $managerEmail = $this->settings->string(
            'contact_merchandising_email',
            $this->config->string('mail.to.orders'),
        );

        $managerSent = $this->mail->send(
            $managerEmail,
            sprintf('Nuovo ordine %s - %s', $order->orderNumber, $order->customerName()),
            'emails/order-manager.twig',
            ['order' => $order, 'items' => $order->items],
            replyTo: $order->customerEmail,
        );

        $customerSent = $this->mail->send(
            $order->customerEmail,
            sprintf('Abbiamo ricevuto il tuo ordine %s', $order->orderNumber),
            'emails/order-customer.twig',
            [
                'order' => $order,
                'items' => $order->items,
                'payment_instructions' => $this->settings->string('shop_payment_instructions'),
                'contact_email' => $managerEmail,
                'group_name' => $this->settings->string('site_group_name', 'Baraonda Fiorentina'),
            ],
            replyTo: $managerEmail,
        );

        return ['manager' => $managerSent, 'customer' => $customerSent];
    }

    /**
     * Segna un ordine come completato, o lo riporta fra quelli da gestire.
     *
     * Il ritorno indietro esiste perche il pulsante e uno solo e si preme in
     * fretta: senza, un clic sbagliato lascerebbe per sempre "completato" un
     * ordine che nessuno ha ancora consegnato.
     */
    public function setCompleted(int $orderId, bool $completed, User $actor): bool
    {
        $order = $this->orders->find($orderId, withItems: false);

        if ($order === null) {
            return false;
        }

        $nuovo = $completed ? Order::STATUS_COMPLETED : Order::STATUS_NEW;

        if ($order->status === $nuovo) {
            return true;
        }

        $aggiornato = $this->orders->setCompleted($orderId, $completed);

        if ($aggiornato) {
        }

        return $aggiornato;
    }
}
