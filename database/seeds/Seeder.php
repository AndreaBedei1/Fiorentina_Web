<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Console;
use App\Core\Application;
use App\Core\Database\Connection;

/**
 * Base dei seeder.
 *
 * I dati dimostrativi sono realistici e chiaramente sostituibili: niente lorem
 * ipsum, ma testi che raccontano davvero cosa fa un gruppo organizzato. Chi
 * riceve il sito vede subito come apparira una volta compilato, e capisce dove
 * mettere le mani.
 *
 * Ogni seeder e idempotente: rilanciarlo non crea duplicati.
 */
abstract class Seeder
{
    public function __construct(
        protected readonly Application $app,
        protected readonly Connection $db,
    ) {
    }

    /** Nome mostrato durante l'esecuzione. */
    abstract public function name(): string;

    /** @return int Numero di elementi creati. */
    abstract public function run(): int;

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Data relativa a oggi, comoda per generare contenuti sempre attuali. */
    protected function daysFromNow(int $days, string $time = '21:00'): string
    {
        return date('Y-m-d ' . $time . ':00', strtotime(sprintf('%+d days', $days)));
    }

    protected function say(string $message): void
    {
        Console::bullet($message);
    }

    /** Verifica se una tabella contiene gia dati: evita di duplicare i seed. */
    protected function tableHasRows(string $table, string $where = '', array $bindings = []): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($table);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->db->scalar($sql, $bindings) > 0;
    }
}
