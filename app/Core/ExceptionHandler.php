<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\ViewRenderer;
use App\Exceptions\HttpException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Punto unico di gestione degli errori.
 *
 * Regola centrale: in produzione il visitatore vede una pagina curata e nulla
 * di piu; il dettaglio tecnico finisce nei log. In sviluppo succede il
 * contrario, perche cercare uno stack trace nei file rallenta il lavoro.
 */
final class ExceptionHandler
{
    /** Codici di stato per cui esiste un template dedicato. */
    private const TEMPLATED_STATUSES = [403, 404, 419, 429, 500, 503];

    public function __construct(
        private readonly Application $app,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleUncaught(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    /** Trasforma i warning/notice PHP in eccezioni, cosi non passano inosservati. */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    public function handleUncaught(Throwable $e): void
    {
        $request = $this->app->has(Request::class)
            ? $this->app->get(Request::class)
            : Request::capture();

        $this->render($request, $e)->send();
    }

    /** Intercetta gli errori fatali, che non passano da set_exception_handler(). */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || ! in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            return;
        }

        $this->handleUncaught(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line'],
        ));
    }

    public function render(Request $request, Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        $this->report($e, $request, $status);

        if ($request->expectsJson()) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->publicMessage($e, $status),
                'debug' => $this->app->isDebug() ? $this->debugPayload($e) : null,
            ], $status, $headers);
        }

        if ($this->app->isDebug() && $status === 500) {
            return $this->renderDebugPage($e)->withHeaders($headers);
        }

        return $this->renderErrorPage($request, $e, $status)->withHeaders($headers);
    }

    /** Registra l'errore. I 4xx non sono bug: restano a livello informativo. */
    private function report(Throwable $e, Request $request, int $status): void
    {
        $context = [
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ];

        if ($status >= 500) {
            $context['trace'] = $e->getTraceAsString();
            $this->logger->error($e->getMessage(), $context);

            return;
        }

        if ($status === 404) {
            $this->logger->info('Pagina non trovata', $context);

            return;
        }

        $this->logger->warning($e->getMessage(), $context);
    }

    private function renderErrorPage(Request $request, Throwable $e, int $status): Response
    {
        $template = in_array($status, self::TEMPLATED_STATUSES, true) ? $status : 500;

        try {
            /** @var ViewRenderer $view */
            $view = $this->app->get(ViewRenderer::class);

            $html = $view->render('errors/' . $template . '.twig', [
                'status' => $status,
                'message' => $this->publicMessage($e, $status),
                'isAdminArea' => str_starts_with($request->path(), '/admin'),
            ]);

            return Response::html($html, $status);
        } catch (Throwable $renderFailure) {
            // Il rendering dell'errore e fallito a sua volta: restiamo essenziali,
            // ma lasciamo traccia della causa reale nei log.
            $this->logger->critical('Rendering della pagina di errore non riuscito.', [
                'original' => $e->getMessage(),
                'render_error' => $renderFailure->getMessage(),
            ]);

            return Response::html($this->fallbackHtml($status, $this->publicMessage($e, $status)), $status);
        }
    }

    /** Pagina diagnostica di sviluppo: mostrata solo con APP_DEBUG=true. */
    private function renderDebugPage(Throwable $e): Response
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $frames = '';

        foreach (array_slice($e->getTrace(), 0, 25) as $index => $frame) {
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $location = isset($frame['file'])
                ? $frame['file'] . ':' . ($frame['line'] ?? '?')
                : '[funzione interna]';

            $frames .= sprintf(
                '<li><span class="idx">#%d</span><code>%s</code><small>%s</small></li>',
                $index,
                $escape($function),
                $escape($location),
            );
        }

        $previous = '';

        if ($e->getPrevious() instanceof Throwable) {
            $previous = sprintf(
                '<p class="prev">Causata da: <strong>%s</strong> - %s</p>',
                $escape($e->getPrevious()::class),
                $escape($e->getPrevious()->getMessage()),
            );
        }

        // I valori vanno precalcolati: l'interpolazione nelle heredoc non
        // ammette chiamate a funzione.
        $class = $escape($e::class);
        $message = $escape($e->getMessage());
        $file = $escape($e->getFile());
        $line = (int) $e->getLine();

        $html = <<<HTML
        <!doctype html>
        <html lang="it"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Errore applicativo</title>
        <style>
          :root { color-scheme: dark; }
          body { margin:0; background:#1a0d27; color:#f3f1ed; font:15px/1.6 ui-sans-serif,system-ui,sans-serif; }
          .wrap { max-width:60rem; margin:0 auto; padding:2.5rem 1.25rem 4rem; }
          .tag { display:inline-block; background:#ac173a; color:#fff; font-size:.7rem; letter-spacing:.12em;
                 text-transform:uppercase; padding:.3rem .6rem; border-radius:.3rem; font-weight:700; }
          h1 { font-size:1.5rem; margin:.9rem 0 .4rem; line-height:1.25; }
          .loc { color:#bda3e0; font-family:ui-monospace,Consolas,monospace; font-size:.85rem; word-break:break-all; }
          .prev { color:#f7a4af; }
          h2 { font-size:.75rem; text-transform:uppercase; letter-spacing:.12em; color:#9d76ce; margin:2rem 0 .75rem; }
          ol { list-style:none; margin:0; padding:0; }
          li { padding:.6rem .8rem; border-left:2px solid #41215f; margin-bottom:.3rem; background:#2c1640; border-radius:0 .4rem .4rem 0; }
          .idx { color:#8151b8; font-family:ui-monospace,monospace; margin-right:.6rem; font-size:.8rem; }
          code { color:#f3f1ed; font-size:.85rem; }
          small { display:block; color:#b8ae9e; font-family:ui-monospace,monospace; font-size:.75rem; margin-top:.2rem; word-break:break-all; }
          .note { margin-top:2.5rem; padding:.9rem 1rem; background:#2c1640; border-radius:.6rem; color:#d5cec3; font-size:.85rem; }
        </style></head><body><div class="wrap">
          <span class="tag">{$class}</span>
          <h1>{$message}</h1>
          <p class="loc">{$file}:{$line}</p>
          {$previous}
          <h2>Stack trace</h2>
          <ol>{$frames}</ol>
          <p class="note">Questa pagina compare solo con <code>APP_DEBUG=true</code>.
          In produzione il visitatore vede la pagina 500 del sito e il dettaglio finisce in
          <code>storage/logs/app.log</code>.</p>
        </div></body></html>
        HTML;

        return Response::html($html, 500);
    }

    /** Messaggio mostrabile al pubblico: mai il testo grezzo di un errore 500. */
    private function publicMessage(Throwable $e, int $status): string
    {
        if ($e instanceof HttpException && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return match ($status) {
            400 => 'La richiesta non e valida.',
            401 => 'Devi autenticarti per accedere a questa pagina.',
            403 => 'Non hai i permessi necessari per accedere a questa pagina.',
            404 => 'La pagina che cerchi non esiste o e stata spostata.',
            419 => 'La sessione e scaduta. Ricarica la pagina e riprova.',
            429 => 'Troppi tentativi ravvicinati. Attendi qualche minuto e riprova.',
            503 => 'Il sito e temporaneamente in manutenzione.',
            default => 'Si e verificato un errore imprevisto. Ci stiamo lavorando.',
        };
    }

    /** @return array<string, mixed> */
    private function debugPayload(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    private function fallbackHtml(int $status, string $message): string
    {
        $status = (int) $status;
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <!doctype html><html lang="it"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Errore {$status}</title></head>
        <body style="margin:0;display:grid;place-items:center;min-height:100vh;background:#41215f;color:#fff;
                     font:16px/1.6 ui-sans-serif,system-ui,sans-serif;text-align:center;padding:2rem">
        <div><p style="font-size:3rem;margin:0 0 .5rem;font-weight:800">{$status}</p>
        <p style="margin:0 0 1.5rem;opacity:.85">{$message}</p>
        <a href="/" style="color:#fff;text-decoration:underline">Torna alla home</a></div>
        </body></html>
        HTML;
    }
}
