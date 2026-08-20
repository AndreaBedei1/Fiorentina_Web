<?php

declare(strict_types=1);

namespace App\Core\Database;

use Closure;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Connessione MySQL basata su PDO.
 *
 * Scelte non negoziabili, applicate qui una volta per tutte:
 *  - ERRMODE_EXCEPTION: nessun errore silenzioso;
 *  - EMULATE_PREPARES = false: prepared statement veri lato server, quindi i
 *    parametri non vengono mai interpolati nella query (difesa SQL injection);
 *  - utf8mb4: accenti ed emoji gestiti correttamente.
 *
 * La connessione e pigra: una pagina che non tocca il database non la apre.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $transactionLevel = 0;

    /** @var list<array{sql: string, time: float}> */
    private array $queryLog = [];

    private bool $logging = false;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = (string) ($this->config['driver'] ?? 'mysql');
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 3306);
        $database = (string) ($this->config['database'] ?? '');
        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');

        $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $driver, $host, $port, $database, $charset);

        $initCommand = sprintf(
            "SET NAMES %s COLLATE %s, sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone = '%s'",
            $charset,
            (string) ($this->config['collation'] ?? 'utf8mb4_unicode_ci'),
            $this->mysqlTimezoneOffset(),
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => $initCommand,
                ],
            );
        } catch (PDOException $e) {
            // Il messaggio originale di PDO può contenere le credenziali: lo sostituiamo.
            throw new \RuntimeException(sprintf(
                'Connessione al database non riuscita (host %s:%d, database "%s"). Verifica le variabili DB_* nel file .env.',
                $host,
                $port,
                $database,
            ), (int) $e->getCode());
        }

        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function enableQueryLog(bool $enabled = true): void
    {
        $this->logging = $enabled;
    }

    /** @return list<array{sql: string, time: float}> */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    // -----------------------------------------------------------------------
    //  Lettura
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>|list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<string, mixed>|list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed>|list<mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed>|list<mixed> $bindings
     * @return list<mixed>
     */
    public function column(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Mappa chiave => valore costruita dalle prime due colonne della SELECT.
     *
     * @param array<string, mixed>|list<mixed> $bindings
     * @return array<array-key, mixed>
     */
    public function pairs(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // -----------------------------------------------------------------------
    //  Scrittura
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>|list<mixed> $bindings
     * @return int Numero di righe modificate.
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * @param array<string, mixed>|list<mixed> $bindings
     * @return int ID auto-increment appena generato.
     */
    public function insert(string $sql, array $bindings = []): int
    {
        $this->run($sql, $bindings);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Inserisce una riga costruendo la query dai nomi di colonna.
     *
     * Le chiavi arrivano sempre da codice applicativo, mai direttamente
     * dall'input HTTP: sono i repository a comporre esplicitamente l'array, ed e
     * questa la barriera contro il mass assignment.
     *
     * @param array<string, mixed> $data
     */
    public function insertInto(string $table, array $data): int
    {
        $columns = array_keys($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );

        return $this->insert($sql, $data);
    }

    /**
     * Aggiorna una riga per chiave primaria.
     *
     * @param array<string, mixed> $data
     */
    public function updateWhereId(string $table, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $assignments = implode(', ', array_map(
            fn (string $c): string => $this->quoteIdentifier($c) . ' = :' . $c,
            array_keys($data),
        ));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :pk_id',
            $this->quoteIdentifier($table),
            $assignments,
        );

        return $this->statement($sql, $data + ['pk_id' => $id]);
    }

    // -----------------------------------------------------------------------
    //  Transazioni
    // -----------------------------------------------------------------------

    /**
     * Esegue la callback in transazione, con rollback automatico su eccezione.
     * Le chiamate annidate riusano la transazione esterna tramite SAVEPOINT.
     *
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT trans' . ($this->transactionLevel + 1));
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            return;
        }

        if ($this->transactionLevel === 1) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT trans' . $this->transactionLevel);
        }

        $this->transactionLevel--;
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel <= 0) {
            return;
        }

        if ($this->transactionLevel === 1) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT ' . 'trans' . $this->transactionLevel);
        }

        $this->transactionLevel--;
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    // -----------------------------------------------------------------------
    //  Utilita
    // -----------------------------------------------------------------------

    /** Esegue SQL grezzo senza binding: riservato a migrazioni e DDL. */
    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_map('strval', $this->column('SHOW TABLES'));
    }

    public function tableExists(string $table): bool
    {
        $found = $this->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        );

        return (int) $found > 0;
    }

    /**
     * Protegge un identificatore SQL.
     *
     * Gli identificatori non possono essere passati come parametri: li
     * accettiamo solo se composti da caratteri sicuri, e non li "escapiamo".
     */
    public function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Identificatore SQL non valido: "%s".', $identifier));
        }

        return '`' . $identifier . '`';
    }

    /** @param array<string, mixed>|list<mixed> $bindings */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $started = $this->logging ? microtime(true) : 0.0;

        [$sql, $bindings] = $this->expandRepeatedPlaceholders($sql, $bindings);

        $statement = $this->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');

            $statement->bindValue($parameter, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            });
        }

        $statement->execute();

        if ($this->logging) {
            $this->queryLog[] = ['sql' => $sql, 'time' => (microtime(true) - $started) * 1000];
        }

        return $statement;
    }

    /**
     * Sdoppia i segnaposto con nome usati più volte nella stessa query.
     *
     * Con i prepared statement nativi (EMULATE_PREPARES = false) MySQL rifiuta
     * di riutilizzare lo stesso `:nome` in due punti diversi e risponde
     * "Invalid parameter number". Scrivere pero
     *
     *     WHERE titolo LIKE :ricerca OR sommario LIKE :ricerca
     *
     * e il modo naturale di esprimere quella condizione: costringere chi scrive
     * query a inventare `:ricerca2`, `:ricerca3` significa lasciare una
     * trappola che si manifesta solo quando qualcuno usa quel filtro, cioè
     * spesso solo in produzione.
     *
     * Qui la occorrenze successive alla prima vengono rinominate e il valore
     * duplicato, in modo trasparente per chi scrive il repository.
     *
     * @param array<string, mixed>|list<mixed> $bindings
     * @return array{0: string, 1: array<string, mixed>|list<mixed>}
     */
    private function expandRepeatedPlaceholders(string $sql, array $bindings): array
    {
        // I binding posizionali (?) non hanno questo problema.
        if ($bindings === [] || array_is_list($bindings)) {
            return [$sql, $bindings];
        }

        $length = strlen($sql);
        $result = '';
        $seen = [];
        $extra = [];

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // I segnaposto dentro stringhe o identificatori non vanno toccati.
            if ($char === "'" && ! $inDouble && ! $inBacktick) {
                $inSingle = ! $inSingle;
            } elseif ($char === '"' && ! $inSingle && ! $inBacktick) {
                $inDouble = ! $inDouble;
            } elseif ($char === '`' && ! $inSingle && ! $inDouble) {
                $inBacktick = ! $inBacktick;
            }

            if ($char !== ':' || $inSingle || $inDouble || $inBacktick) {
                $result .= $char;

                continue;
            }

            // Legge il nome del segnaposto.
            $name = '';
            $j = $i + 1;

            while ($j < $length && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                $name .= $sql[$j];
                $j++;
            }

            if ($name === '' || ! array_key_exists($name, $bindings)) {
                $result .= $char;

                continue;
            }

            $seen[$name] = ($seen[$name] ?? 0) + 1;

            if ($seen[$name] === 1) {
                $result .= ':' . $name;
            } else {
                $alias = $name . '_dup' . $seen[$name];
                $extra[$alias] = $bindings[$name];
                $result .= ':' . $alias;
            }

            $i = $j - 1;
        }

        return [$result, $extra === [] ? $bindings : $bindings + $extra];
    }

    /** MySQL vuole un offset numerico: lo deriviamo dal fuso applicativo. */
    private function mysqlTimezoneOffset(): string
    {
        $timezone = (string) ($this->config['timezone'] ?? date_default_timezone_get());

        try {
            $offset = (new \DateTimeZone($timezone))->getOffset(new \DateTimeImmutable('now'));
        } catch (\Exception) {
            return '+00:00';
        }

        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }
}
