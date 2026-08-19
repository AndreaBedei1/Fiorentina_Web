<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Application;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Http\Request;
use App\Core\Security\Hash;
use App\Models\User;
use App\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Base dei test di integrazione.
 *
 * Lo schema viene ricostruito una sola volta per esecuzione della suite, non
 * a ogni test: sono ventisette tabelle e ricrearle centinaia di volte
 * renderebbe i test cosi lenti da smettere di lanciarli. L isolamento fra test
 * si ottiene svuotando le tabelle in setUp().
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ?Application $app = null;

    private static bool $schemaReady = false;

    protected Connection $db;

    public static function setUpBeforeClass(): void
    {
        self::$app ??= (new Application(dirname(__DIR__, 2)))->boot();

        if (self::$schemaReady) {
            return;
        }

        $connection = self::$app->get(Connection::class);

        // Salvaguardia: la suite deve girare solo sul database dedicato.
        $database = (string) $connection->scalar('SELECT DATABASE()');

        if (! str_contains($database, 'test')) {
            throw new \RuntimeException(sprintf(
                'I test di integrazione richiedono un database dedicato (nome contenente "test"), trovato "%s". '
                . 'Controlla DB_TEST_DATABASE nel file .env.',
                $database,
            ));
        }

        $migrator = new Migrator($connection, self::$app->databasePath('migrations'));
        $migrator->fresh();

        self::$schemaReady = true;
    }

    protected function setUp(): void
    {
        $this->db = self::app()->get(Connection::class);
        $this->truncateAll();
    }

    protected static function app(): Application
    {
        if (self::$app === null) {
            throw new \RuntimeException('Applicazione non inizializzata.');
        }

        return self::$app;
    }

    /** Svuota tutte le tabelle tranne quella delle migrazioni. */
    protected function truncateAll(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->db->tables() as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $this->db->exec('TRUNCATE TABLE ' . $this->db->quoteIdentifier($table));
        }

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Crea un amministratore di prova.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createUser(array $overrides = []): User
    {
        $users = self::app()->get(UserRepository::class);
        $hash = self::app()->get(Hash::class);

        $password = $overrides['password'] ?? Fixtures::PASSWORD;
        unset($overrides['password']);

        $id = $users->create(array_merge([
            'name' => 'Amministratore di prova',
            'email' => 'admin-' . bin2hex(random_bytes(4)) . '@example.test',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'password_hash' => $hash->make($password),
        ], $overrides));

        $user = $users->find($id);

        if ($user === null) {
            throw new \RuntimeException('Creazione dell utente di prova non riuscita.');
        }

        return $user;
    }

    protected function createSuperAdmin(array $overrides = []): User
    {
        return $this->createUser(array_merge(['role' => User::ROLE_SUPER_ADMIN], $overrides));
    }

    /** Sostituisce la richiesta corrente nel container: serve ai service che leggono IP e user agent. */
    protected function fakeRequest(string $method = 'GET', string $path = '/', array $body = []): Request
    {
        $request = Request::create($method, $path, $body, [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'PHPUnit/Test',
        ]);

        self::app()->instance(Request::class, $request);

        return $request;
    }

    /** Avvia una sessione in memoria: i test CLI non hanno una sessione HTTP. */
    protected function startSession(): void
    {
        if (! isset($_SESSION)) {
            $_SESSION = [];
        }

        self::app()->get(\App\Core\Session\Session::class)->start();
    }

    protected function resetSession(): void
    {
        $_SESSION = [];
    }
}
