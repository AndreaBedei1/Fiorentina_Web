<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Models\Order;
use App\Models\OrderItem;

/** Accesso alle richieste d'ordine del merchandising. */
final class OrderRepository extends BaseRepository
{
    protected string $table = 'orders';

    public function find(int $id, bool $withItems = true): ?Order
    {
        $row = $this->db->selectOne(
            'SELECT * FROM orders WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        if ($row === null) {
            return null;
        }

        return Order::fromRow($row, $withItems ? $this->itemsFor($id) : []);
    }

    public function findByNumber(string $orderNumber): ?Order
    {
        $row = $this->db->selectOne(
            'SELECT * FROM orders WHERE order_number = :number AND deleted_at IS NULL',
            ['number' => $orderNumber],
        );

        if ($row === null) {
            return null;
        }

        return Order::fromRow($row, $this->itemsFor((int) $row['id']));
    }

    /** @return list<OrderItem> */
    public function itemsFor(int $orderId): array
    {
        return array_map(
            OrderItem::fromRow(...),
            $this->db->select('SELECT * FROM order_items WHERE order_id = :order ORDER BY id ASC', ['order' => $orderId]),
        );
    }

    /** @return Paginator<Order> */
    public function paginateForAdmin(
        int $page,
        int $perPage = 20,
        ?string $status = null,
        string $search = '',
        string $basePath = '',
    ): Paginator {
        $conditions = ['o.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== null && $status !== '' && in_array($status, Order::allStatuses(), true)) {
            $conditions[] = 'o.status = :status';
            $bindings['status'] = $status;
        }

        if (trim($search) !== '') {
            $conditions[] = '(o.order_number LIKE :search
                OR o.customer_email LIKE :search
                OR CONCAT(o.customer_first_name, " ", o.customer_last_name) LIKE :search)';
            $bindings['search'] = '%' . trim($search) . '%';
        }

        $where = implode(' AND ', $conditions);

        return $this->paginateQuery(
            'SELECT o.* FROM orders o WHERE ' . $where . ' ORDER BY o.created_at DESC',
            'SELECT COUNT(*) FROM orders o WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            // Le righe d'ordine non servono in elenco: le carica solo il dettaglio.
            static fn (array $row): Order => Order::fromRow($row),
            $basePath,
            array_filter(['stato' => $status, 'q' => $search !== '' ? $search : null]),
        );
    }

    /** @return list<Order> */
    public function recent(int $limit = 5): array
    {
        $rows = $this->db->select(
            'SELECT * FROM orders WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT ' . max(1, min(20, $limit)),
        );

        return array_map(static fn (array $row): Order => Order::fromRow($row), $rows);
    }

    /**
     * Crea l'ordine e le sue righe in un'unica transazione.
     *
     * Numerazione e righe devono comparire insieme: un ordine senza articoli, o
     * due ordini con lo stesso numero, sarebbero entrambi ingestibili per il
     * responsabile merchandising.
     *
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $items
     */
    public function createWithItems(array $order, array $items): int
    {
        return $this->db->transaction(function () use ($order, $items): int {
            $now = $this->now();

            $order['order_number'] = $this->nextOrderNumber();
            $order['created_at'] = $now;
            $order['updated_at'] = $now;

            $orderId = $this->db->insertInto('orders', $order);

            foreach ($items as $item) {
                $item['order_id'] = $orderId;
                $item['created_at'] = $now;
                $this->db->insertInto('order_items', $item);
            }

            $this->db->insertInto('order_status_history', [
                'order_id' => $orderId,
                'from_status' => null,
                'to_status' => Order::STATUS_NEW,
                'note' => 'Ordine ricevuto dal sito',
                'changed_by' => null,
                'created_at' => $now,
            ]);

            return $orderId;
        });
    }

    /**
     * Numero d'ordine progressivo per anno: BF-2026-000001.
     *
     * Il contatore viene bloccato con SELECT ... FOR UPDATE: con due ordini
     * inviati nello stesso istante, un semplice MAX(id)+1 produrrebbe duplicati.
     * Da chiamare solo all'interno di una transazione.
     */
    private function nextOrderNumber(): string
    {
        $year = (int) date('Y');
        $prefix = (string) config('shop.order_prefix', 'BF');

        $this->db->statement(
            'INSERT INTO order_sequences (year, last_number, updated_at)
             VALUES (:year, 0, :now)
             ON DUPLICATE KEY UPDATE year = year',
            ['year' => $year, 'now' => $this->now()],
        );

        $current = (int) $this->db->scalar(
            'SELECT last_number FROM order_sequences WHERE year = :year FOR UPDATE',
            ['year' => $year],
        );

        $next = $current + 1;

        $this->db->statement(
            'UPDATE order_sequences SET last_number = :next, updated_at = :now WHERE year = :year',
            ['next' => $next, 'now' => $this->now(), 'year' => $year],
        );

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    /** Aggiorna lo stato registrandone il passaggio nello storico. */
    public function updateStatus(int $orderId, string $newStatus, ?int $changedBy, ?string $note = null): bool
    {
        if (! in_array($newStatus, Order::allStatuses(), true)) {
            return false;
        }

        return $this->db->transaction(function () use ($orderId, $newStatus, $changedBy, $note): bool {
            $current = $this->db->selectOne('SELECT status FROM orders WHERE id = :id', ['id' => $orderId]);

            if ($current === null) {
                return false;
            }

            $from = (string) $current['status'];

            if ($from === $newStatus) {
                return true;
            }

            $this->db->updateWhereId('orders', $orderId, [
                'status' => $newStatus,
                'updated_at' => $this->now(),
            ]);

            $this->db->insertInto('order_status_history', [
                'order_id' => $orderId,
                'from_status' => $from,
                'to_status' => $newStatus,
                'note' => $note,
                'changed_by' => $changedBy,
                'created_at' => $this->now(),
            ]);

            return true;
        });
    }

    /** @return list<array<string, mixed>> */
    public function statusHistory(int $orderId): array
    {
        return $this->db->select(
            'SELECT h.*, u.name AS changed_by_name
             FROM order_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.order_id = :order ORDER BY h.created_at ASC, h.id ASC',
            ['order' => $orderId],
        );
    }

    public function updateAdminNotes(int $orderId, ?string $notes): bool
    {
        return $this->db->updateWhereId('orders', $orderId, [
            'admin_notes' => $notes,
            'updated_at' => $this->now(),
        ]) >= 0;
    }

    public function markNotified(int $orderId, bool $manager, bool $customer): void
    {
        $data = ['updated_at' => $this->now()];

        if ($manager) {
            $data['manager_notified_at'] = $this->now();
        }

        if ($customer) {
            $data['customer_notified_at'] = $this->now();
        }

        $this->db->updateWhereId('orders', $orderId, $data);
    }

    /** Archiviazione: un ordine non viene mai cancellato fisicamente. */
    public function delete(int $id): bool
    {
        return $this->softDelete($id);
    }

    /** @return array{total: int, new: int, open: int, month_total: float} */
    public function statistics(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'NEW') AS new_orders,
                    SUM(status NOT IN ('COMPLETED', 'CANCELLED')) AS open_orders,
                    COALESCE(SUM(CASE
                        WHEN status <> 'CANCELLED' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                        THEN total ELSE 0 END), 0) AS month_total
             FROM orders WHERE deleted_at IS NULL"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'new' => (int) ($row['new_orders'] ?? 0),
            'open' => (int) ($row['open_orders'] ?? 0),
            'month_total' => (float) ($row['month_total'] ?? 0),
        ];
    }

    /** @return array<string, int> Conteggio ordini per stato, per i filtri rapidi. */
    public function countsByStatus(): array
    {
        $counts = [];

        foreach ($this->db->select(
            'SELECT status, COUNT(*) AS total FROM orders WHERE deleted_at IS NULL GROUP BY status'
        ) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
