<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Esecutore di migrazioni basato su file .sql.
 *
 * Perche SQL puro e non un DSL PHP: su Aruba Easy non c'e SSH, e l'unico modo
 * pratico per creare lo schema resta l'import di un dump da phpMyAdmin. Tenendo
 * le migrazioni in SQL leggibile lo stesso file serve sia al comando locale sia
 * all'import manuale, senza doppioni che si disallineano.
 *
 * Formato dei file: `NNN_descrizione.sql`, opzionalmente con una sezione di
 * rollback introdotta dalla riga `-- @DOWN`.
 */
final class Migrator
{
    private const TABLE = 'migrations';
    private const DOWN_MARKER = '-- @DOWN';

    /** @var list<string> Messaggi prodotti dall'ultima esecuzione. */
    private array $output = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsPath,
    ) {
    }

    /** @return list<string> */
    public function output(): array
    {
        return $this->output;
    }

    /**
     * Applica le migrazioni non ancora eseguite.
     *
     * @return int Numero di migrazioni applicate.
     */
    public function migrate(): int
    {
        $this->ensureMigrationsTable();

        $pending = $this->pending();

        if ($pending === []) {
            $this->say('Nessuna migrazione da applicare: il database e aggiornato.');

            return 0;
        }

        $batch = $this->currentBatch() + 1;

        foreach ($pending as $migration) {
            $this->runMigration($migration, $batch);
        }

        return count($pending);
    }

    /**
     * Elimina tutte le tabelle e riapplica l'intero schema da zero.
     * Usata in sviluppo e dalla suite di integration test.
     */
    public function fresh(): int
    {
        $this->dropAllTables();
        $this->say('Tutte le tabelle sono state eliminate.');

        return $this->migrate();
    }

    /**
     * Annulla l'ultimo lotto di migrazioni, se dispongono di una sezione @DOWN.
     *
     * @return int Numero di migrazioni annullate.
     */
    public function rollback(): int
    {
        $this->ensureMigrationsTable();

        $batch = $this->currentBatch();

        if ($batch === 0) {
            $this->say('Nessun lotto di migrazioni da annullare.');

            return 0;
        }

        $migrations = $this->connection->column(
            'SELECT migration FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY id DESC',
            [$batch],
        );

        $count = 0;

        foreach ($migrations as $migration) {
            $migration = (string) $migration;
            $down = $this->downSql($migration);

            if ($down === null) {
                $this->say(sprintf('  ! %s non ha una sezione @DOWN: salto.', $migration));

                continue;
            }

            foreach ($this->splitStatements($down) as $statement) {
                $this->connection->exec($statement);
            }

            $this->connection->statement('DELETE FROM ' . self::TABLE . ' WHERE migration = ?', [$migration]);
            $this->say(sprintf('  - annullata %s', $migration));
            $count++;
        }

        return $count;
    }

    /** @return list<string> Nomi dei file di migrazione non ancora applicati. */
    public function pending(): array
    {
        $this->ensureMigrationsTable();

        $applied = array_map('strval', $this->connection->column('SELECT migration FROM ' . self::TABLE));

        return array_values(array_diff($this->availableMigrations(), $applied));
    }

    /** @return list<string> */
    public function availableMigrations(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];

        $names = array_map(static fn (string $file): string => basename($file), $files);

        // L'ordine alfabetico e l'ordine di esecuzione: da qui il prefisso numerico.
        sort($names, SORT_STRING);

        return array_values($names);
    }

    public function ensureMigrationsTable(): void
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' migration VARCHAR(255) NOT NULL,'
            . ' batch INT UNSIGNED NOT NULL,'
            . ' executed_at DATETIME NOT NULL,'
            . ' PRIMARY KEY (id),'
            . ' UNIQUE KEY uniq_migration (migration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** Elimina tutte le tabelle dello schema corrente, foreign key incluse. */
    public function dropAllTables(): void
    {
        $tables = $this->connection->tables();

        if ($tables === []) {
            return;
        }

        $this->connection->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $this->connection->exec('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier($table));
        }

        $this->connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function runMigration(string $migration, int $batch): void
    {
        $sql = $this->upSql($migration);
        $statements = $this->splitStatements($sql);

        if ($statements === []) {
            $this->say(sprintf('  ! %s non contiene istruzioni: salto.', $migration));

            return;
        }

        $started = microtime(true);

        /*
         * MySQL applica il DDL con commit implicito: una transazione qui darebbe
         * una falsa sensazione di atomicita. Per questo ogni migrazione resta
         * piccola è indipendente, e un errore viene segnalato indicando il file
         * esatto in cui intervenire.
         */
        foreach ($statements as $index => $statement) {
            try {
                $this->connection->exec($statement);
            } catch (\PDOException $e) {
                throw new \RuntimeException(sprintf(
                    "Migrazione \"%s\" fallita all'istruzione #%d.\n%s\n\nSQL:\n%s",
                    $migration,
                    $index + 1,
                    $e->getMessage(),
                    mb_substr($statement, 0, 500),
                ), 0, $e);
            }
        }

        $this->connection->insertInto(self::TABLE, [
            'migration' => $migration,
            'batch' => $batch,
            'executed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->say(sprintf('  + %s (%.0f ms)', $migration, (microtime(true) - $started) * 1000));
    }

    private function currentBatch(): int
    {
        return (int) $this->connection->scalar('SELECT COALESCE(MAX(batch), 0) FROM ' . self::TABLE);
    }

    private function contents(string $migration): string
    {
        $path = rtrim($this->migrationsPath, '/\\') . DIRECTORY_SEPARATOR . $migration;

        if (! is_file($path)) {
            throw new \RuntimeException(sprintf('File di migrazione non trovato: %s', $path));
        }

        return (string) file_get_contents($path);
    }

    private function upSql(string $migration): string
    {
        $contents = $this->contents($migration);
        $position = stripos($contents, self::DOWN_MARKER);

        return $position === false ? $contents : substr($contents, 0, $position);
    }

    private function downSql(string $migration): ?string
    {
        $contents = $this->contents($migration);
        $position = stripos($contents, self::DOWN_MARKER);

        if ($position === false) {
            return null;
        }

        return substr($contents, $position + strlen(self::DOWN_MARKER));
    }

    /**
     * Divide uno script SQL in istruzioni.
     *
     * Un semplice explode(';') romperebbe sui punti e virgola dentro le
     * stringhe (per esempio nei valori di seed), quindi scorriamo i caratteri
     * tenendo traccia di apici, virgolette e commenti.
     *
     * @return list<string>
     */
    public function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if (! $inSingle && ! $inDouble && ! $inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;

                    continue;
                }

                if ($char === '#') {
                    $inLineComment = true;

                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;

                    continue;
                }
            }

            if ($char === "'" && ! $inDouble && ! $inBacktick) {
                // Un apice raddoppiato ('') e un apice letterale, non una chiusura.
                if ($inSingle && $next === "'") {
                    $buffer .= "''";
                    $i++;

                    continue;
                }

                $inSingle = ! $inSingle;
            } elseif ($char === '"' && ! $inSingle && ! $inBacktick) {
                $inDouble = ! $inDouble;
            } elseif ($char === '`' && ! $inSingle && ! $inDouble) {
                $inBacktick = ! $inBacktick;
            } elseif ($char === '\\' && ($inSingle || $inDouble)) {
                // Sequenza di escape: consumiamo anche il carattere seguente.
                $buffer .= $char . $next;
                $i++;

                continue;
            } elseif ($char === ';' && ! $inSingle && ! $inDouble && ! $inBacktick) {
                $statement = trim($buffer);

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);

        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function say(string $message): void
    {
        $this->output[] = $message;
    }
}
