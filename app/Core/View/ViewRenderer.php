<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Application;
use App\Core\Http\Response;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Facciata sul motore di template.
 *
 * Twig e configurato con autoescape HTML attivo: ogni variabile stampata in un
 * template viene sfuggita per default, ed e il motivo per cui l'XSS non e un
 * rischio diffuso in questo progetto. I pochi punti che stampano HTML gia
 * sanificato usano `|raw` in modo esplicito e localizzato.
 */
final class ViewRenderer
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly Application $app,
        private readonly TwigExtension $extension,
    ) {
    }

    public function twig(): Environment
    {
        if ($this->twig instanceof Environment) {
            return $this->twig;
        }

        $loader = new FilesystemLoader($this->app->resourcePath('views'));

        $debug = $this->app->isDebug();

        $twig = new Environment($loader, [
            'debug' => $debug,
            // In produzione la cache dei template compilati e essenziale: su
            // hosting condiviso la compilazione a ogni richiesta si sente.
            'cache' => $debug ? false : $this->app->storagePath('cache' . DIRECTORY_SEPARATOR . 'twig'),
            'auto_reload' => $debug,
            'strict_variables' => $debug,
            'autoescape' => 'html',
            'charset' => 'UTF-8',
        ]);

        if ($debug) {
            $twig->addExtension(new DebugExtension());
        }

        // Le date sono formattate da App\Core\Support\Dates, non da intl:
        // l'estensione intl non e garantita su tutti i piani hosting.
        $twig->addExtension($this->extension);

        return $this->twig = $twig;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig()->render($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function response(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($template, $data), $status);
    }

    public function exists(string $template): bool
    {
        return $this->twig()->getLoader()->exists($template);
    }
}
