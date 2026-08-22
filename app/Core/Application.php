<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\Router;
use App\Core\Routing\UrlGenerator;
use App\Core\Security\Csrf;
use App\Core\Security\Hash;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Core\View\Vite;
use Dotenv\Dotenv;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Kernel dell'applicazione: carica ambiente e configurazione, registra i
 * servizi nel container, monta le rotte e trasforma una Request in Response.
 *
 * Il progetto e un monolite modulare: un unico processo PHP, moduli separati da
 * confini netti (Controller -> Service -> Repository). Questa classe e il punto
 * in cui quei moduli vengono cablati insieme.
 */
final class Application extends Container
{
    public const VERSION = '1.0.0';

    private static ?self $instance = null;

    private bool $booted = false;

    private ExceptionHandler $exceptionHandler;

    public function __construct(private readonly string $basePath)
    {
        self::$instance = $this;

        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Applicazione non ancora inizializzata.');
        }

        return self::$instance;
    }

    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    // -----------------------------------------------------------------------
    //  Percorsi
    // -----------------------------------------------------------------------

    public function basePath(string $append = ''): string
    {
        return $this->basePath . ($append !== '' ? DIRECTORY_SEPARATOR . ltrim($append, '/\\') : '');
    }

    public function configPath(string $append = ''): string
    {
        return $this->basePath('config' . ($append !== '' ? DIRECTORY_SEPARATOR . $append : ''));
    }

    public function storagePath(string $append = ''): string
    {
        return $this->basePath('storage' . ($append !== '' ? DIRECTORY_SEPARATOR . $append : ''));
    }

    public function publicPath(string $append = ''): string
    {
        return $this->basePath('public' . ($append !== '' ? DIRECTORY_SEPARATOR . $append : ''));
    }

    public function resourcePath(string $append = ''): string
    {
        return $this->basePath('resources' . ($append !== '' ? DIRECTORY_SEPARATOR . $append : ''));
    }

    public function databasePath(string $append = ''): string
    {
        return $this->basePath('database' . ($append !== '' ? DIRECTORY_SEPARATOR . $append : ''));
    }

    // -----------------------------------------------------------------------
    //  Bootstrap
    // -----------------------------------------------------------------------

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->loadEnvironment();
        $this->loadConfiguration();
        $this->configureRuntime();
        $this->registerServices();
        $this->registerErrorHandling();

        $this->booted = true;

        return $this;
    }

    private function loadEnvironment(): void
    {
        if (is_file($this->basePath('.env'))) {
            Dotenv::createImmutable($this->basePath())->safeLoad();
        }
    }

    private function loadConfiguration(): void
    {
        $config = Config::loadFromDirectory($this->configPath());
        $this->instance(Config::class, $config);
    }

    private function configureRuntime(): void
    {
        $config = $this->config();

        date_default_timezone_set($config->string('app.timezone', 'Europe/Rome'));
        mb_internal_encoding('UTF-8');
        setlocale(LC_NUMERIC, 'C'); // evita che una locale con virgola decimale rompa gli import SQL

        if ($config->bool('app.debug')) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            // In produzione gli errori si leggono nei log, mai a schermo.
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
            ini_set('display_errors', '0');
        }

        ini_set('log_errors', '1');
        ini_set('error_log', $this->storagePath('logs' . DIRECTORY_SEPARATOR . 'php-error.log'));
    }

    private function registerServices(): void
    {
        $config = $this->config();

        // --- Logging -------------------------------------------------------
        $this->singleton(LoggerInterface::class, function () use ($config): LoggerInterface {
            $logger = new Logger('baraonda');
            $level = $config->bool('app.debug') ? Level::Debug : Level::Info;

            $logger->pushHandler(new RotatingFileHandler(
                $this->storagePath('logs' . DIRECTORY_SEPARATOR . 'app.log'),
                maxFiles: 14,
                level: $level,
            ));

            if (PHP_SAPI === 'cli') {
                $logger->pushHandler(new StreamHandler('php://stderr', Level::Warning));
            }

            return $logger;
        });
        $this->alias(Logger::class, LoggerInterface::class);

        // --- Database ------------------------------------------------------
        $this->singleton(Connection::class, static fn (): Connection => new Connection([
            'driver' => 'mysql',
            'host' => $config->string('database.host'),
            'port' => $config->int('database.port', 3306),
            'database' => $config->string('database.database'),
            'username' => $config->string('database.username'),
            'password' => $config->string('database.password'),
            'charset' => $config->string('database.charset', 'utf8mb4'),
            'collation' => $config->string('database.collation', 'utf8mb4_unicode_ci'),
            'timezone' => $config->string('app.timezone', 'Europe/Rome'),
        ]));

        /*
         * La richiesta corrente e un servizio come gli altri: diversi service
         * (autenticazione, rate limiting) hanno bisogno di IP e user agent. In HTTP viene sostituita da handle() con quella reale; qui
         * registriamo una versione pigra che serve alla CLI e ai test.
         */
        $this->singleton(Request::class, static fn (): Request => Request::capture());

        // --- Client HTTP verso le API esterne -------------------------------
        $this->singleton(\App\Core\Support\HttpClient::class, fn (): \App\Core\Support\HttpClient => new \App\Core\Support\HttpClient(
            $this->get(LoggerInterface::class),
            $config->int('services.football.timeout', 10),
        ));

        // --- Sessione e CSRF -----------------------------------------------
        $this->singleton(Session::class, fn (): Session => new Session(
            name: $config->string('session.name', 'baraonda_session'),
            lifetimeMinutes: $config->int('session.lifetime', 120),
            secure: $config->bool('session.secure'),
            sameSite: $config->string('session.same_site', 'Lax'),
            savePath: $this->storagePath('sessions'),
        ));

        $this->singleton(Csrf::class);

        // --- Password ------------------------------------------------------
        $this->singleton(Hash::class, static fn (): Hash => new Hash(
            bcryptCost: $config->int('security.bcrypt_cost', 12),
            argonMemoryCost: $config->int('security.argon.memory_cost', 65536),
            argonTimeCost: $config->int('security.argon.time_cost', 4),
            argonThreads: $config->int('security.argon.threads', 2),
        ));

        // --- Routing -------------------------------------------------------
        $this->singleton(Router::class, function (): Router {
            $router = new Router($this);
            $router->registerMiddlewareAliases($this->config()->array('middleware.aliases'));

            foreach (glob($this->basePath('routes') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                (require $file)($router);
            }

            return $router;
        });

        $this->singleton(UrlGenerator::class, fn (): UrlGenerator => new UrlGenerator(
            $this->get(Router::class),
            $this->config()->string('app.url'),
        ));

        // --- View ----------------------------------------------------------
        $this->singleton(Vite::class, fn (): Vite => new Vite(
            manifestPath: $this->publicPath('assets' . DIRECTORY_SEPARATOR . '.vite' . DIRECTORY_SEPARATOR . 'manifest.json'),
            hotFilePath: $this->publicPath('hot'),
            buildDirectory: '/assets',
        ));

        $this->singleton(ViewRenderer::class);
    }

    private function registerErrorHandling(): void
    {
        $this->exceptionHandler = $this->make(ExceptionHandler::class);
        $this->instance(ExceptionHandler::class, $this->exceptionHandler);
        $this->exceptionHandler->register();
    }

    // -----------------------------------------------------------------------
    //  Ciclo richiesta / risposta
    // -----------------------------------------------------------------------

    public function handle(Request $request): Response
    {
        $this->instance(Request::class, $request);

        try {
            $this->get(Session::class)->start();

            return $this->get(Router::class)->dispatch(
                $request,
                $this->config()->array('middleware.global'),
            );
        } catch (\Throwable $e) {
            return $this->exceptionHandler->render($request, $e);
        }
    }

    public function run(): void
    {
        $request = Request::capture();

        $this->handle($request)->send();
    }

    // -----------------------------------------------------------------------
    //  Scorciatoie
    // -----------------------------------------------------------------------

    public function config(): Config
    {
        return $this->get(Config::class);
    }

    public function environment(): string
    {
        return $this->config()->string('app.env', 'production');
    }

    public function isLocal(): bool
    {
        return $this->environment() === 'local';
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isDebug(): bool
    {
        return $this->config()->bool('app.debug');
    }

    public function isConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
