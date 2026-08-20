<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Application;
use App\Core\Http\Request;
use App\Core\Routing\UrlGenerator;
use App\Core\Security\Csrf;
use App\Core\Session\Session;
use App\Core\Support\Dates;
use App\Core\Support\Str;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\SettingsService;
use App\Services\Shop\CartService;
use App\Services\Social\SocialService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Funzioni e filtri disponibili nei template.
 *
 * I servizi che toccano il database (impostazioni, utente autenticato) sono
 * risolti in modo pigro e protetti da try/catch: così la pagina di errore
 * resta renderizzabile anche quando e proprio il database a non rispondere.
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly UrlGenerator $url,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly Vite $vite,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'app_name' => $this->app->config()->string('app.name', 'Baraonda Fiorentina'),
            'app_url' => $this->app->config()->string('app.url'),
            'app_env' => $this->app->environment(),
            'app_debug' => $this->app->isDebug(),
            'current_year' => (int) date('Y'),
            'current_path' => $this->currentPath(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('route', $this->route(...)),
            new TwigFunction('route_absolute', $this->routeAbsolute(...)),
            new TwigFunction('url', $this->url->to(...)),
            new TwigFunction('absolute_url', $this->url->absolute(...)),
            new TwigFunction('vite', $this->vite->tags(...), ['is_safe' => ['html']]),
            new TwigFunction('csrf_token', $this->csrf->token(...)),
            new TwigFunction('csrf_field', $this->csrf->field(...), ['is_safe' => ['html']]),
            new TwigFunction('old', $this->old(...)),
            new TwigFunction('error', $this->error(...)),
            new TwigFunction('has_error', $this->hasError(...)),
            new TwigFunction('flash', $this->session->allFlash(...)),
            new TwigFunction('setting', $this->setting(...)),
            new TwigFunction('current_user', $this->currentUser(...)),
            new TwigFunction('is_logged_in', $this->isLoggedIn(...)),
            new TwigFunction('can', $this->can(...)),
            new TwigFunction('is_active', $this->isActive(...)),
            new TwigFunction('active_class', $this->activeClass(...)),
            new TwigFunction('social_links', $this->socialLinks(...)),
            new TwigFunction('media_url', $this->mediaUrl(...)),
            new TwigFunction('media_srcset', $this->mediaSrcset(...)),
            new TwigFunction('social_thumb', $this->socialThumb(...)),
            new TwigFunction('cart_count', $this->cartCount(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('data', Dates::long(...)),
            new TwigFilter('data_breve', Dates::short(...)),
            new TwigFilter('data_numerica', Dates::numeric(...)),
            new TwigFilter('data_ora', Dates::numericWithTime(...)),
            new TwigFilter('data_estesa', Dates::longWithWeekday(...)),
            new TwigFilter('data_completa', Dates::longWithTime(...)),
            new TwigFilter('ora', Dates::time(...)),
            new TwigFilter('iso', Dates::iso(...)),
            new TwigFilter('iso_data', Dates::isoDate(...)),
            new TwigFilter('giorno', Dates::day(...)),
            new TwigFilter('mese_breve', Dates::monthShort(...)),
            new TwigFilter('relativa', Dates::relative(...)),
            new TwigFilter('euro', Str::money(...)),
            new TwigFilter('estratto', Str::excerpt(...)),
            new TwigFilter('taglia', Str::truncate(...)),
            new TwigFilter('iniziali', Str::initials(...)),
            new TwigFilter('slug', Str::slug(...)),
        ];
    }

    // -----------------------------------------------------------------------
    //  Implementazioni
    // -----------------------------------------------------------------------

    /** @param array<string, string|int> $parameters */
    public function route(string $name, array $parameters = []): string
    {
        return $this->url->route($name, $parameters);
    }

    /** @param array<string, string|int> $parameters */
    public function routeAbsolute(string $name, array $parameters = []): string
    {
        return $this->url->absoluteRoute($name, $parameters);
    }

    public function old(string $key, mixed $default = ''): mixed
    {
        return $this->session->old($key, $default);
    }

    public function error(string $field): ?string
    {
        return $this->session->error($field);
    }

    public function hasError(string $field): bool
    {
        return $this->session->error($field) !== null;
    }

    /** Valore configurabile dal pannello Impostazioni. */
    public function setting(string $key, mixed $default = null): mixed
    {
        try {
            return $this->app->get(SettingsService::class)->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function currentUser(): ?User
    {
        try {
            return $this->app->get(AuthService::class)->user();
        } catch (\Throwable) {
            return null;
        }
    }

    public function isLoggedIn(): bool
    {
        return $this->currentUser() !== null;
    }

    /** Verifica un permesso. Nei template serve solo a nascondere UI inutile:
     *  il controllo vincolante resta lato server nei middleware/controller. */
    public function can(string $permission): bool
    {
        try {
            return $this->app->get(AuthService::class)->can($permission);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Voce di menu attiva: match esatto oppure per prefisso di sezione. */
    public function isActive(string $path, bool $exact = false): bool
    {
        $current = $this->currentPath();
        $path = '/' . trim($path, '/');

        if ($path === '/') {
            return $current === '/';
        }

        return $exact ? $current === $path : ($current === $path || str_starts_with($current, $path . '/'));
    }

    public function activeClass(string $path, string $activeClass, string $inactiveClass = '', bool $exact = false): string
    {
        return $this->isActive($path, $exact) ? $activeClass : $inactiveClass;
    }

    /**
     * Collegamenti social configurati, già filtrati: il footer non deve
     * mostrare icone che puntano a nulla.
     *
     * @return list<array{name: string, label: string, url: string}>
     */
    public function socialLinks(): array
    {
        $definitions = [
            ['name' => 'instagram', 'label' => 'Instagram', 'key' => 'social_instagram_url'],
            ['name' => 'facebook', 'label' => 'Facebook', 'key' => 'social_facebook_url'],
            ['name' => 'youtube', 'label' => 'YouTube', 'key' => 'social_youtube_url'],
            ['name' => 'telegram', 'label' => 'Telegram', 'key' => 'social_telegram_url'],
        ];

        $links = [];

        foreach ($definitions as $definition) {
            $url = (string) $this->setting($definition['key'], '');

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $links[] = ['name' => $definition['name'], 'label' => $definition['label'], 'url' => $url];
            }
        }

        return $links;
    }

    // -----------------------------------------------------------------------
    //  Media
    // -----------------------------------------------------------------------

    /**
     * URL pubblico di un'immagine elaborata.
     *
     * Con chiave nulla o non valida restituisce il segnaposto: nessun template
     * deve preoccuparsi di gestire il caso "immagine assente".
     */
    public function mediaUrl(?string $key, string $collection = 'gallery', string $size = 'medium', string $format = 'webp'): string
    {
        if ($key === null || $key === '') {
            return $this->placeholder();
        }

        try {
            return $this->app->get(MediaPaths::class)->url($collection, $key, $size, $format);
        } catch (\Throwable) {
            return $this->placeholder();
        }
    }

    /** Attributo srcset con tutte le misure disponibili. */
    public function mediaSrcset(?string $key, string $collection = 'gallery', string $format = 'webp'): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        try {
            return $this->app->get(MediaPaths::class)->srcset($collection, $key, $format);
        } catch (\Throwable) {
            return '';
        }
    }

    /** Anteprima di un contenuto social: prima la copia locale, poi l'originale. */
    public function socialThumb(SocialPost $post): ?string
    {
        try {
            return $this->app->get(SocialService::class)->thumbnailUrl($post);
        } catch (\Throwable) {
            return $post->thumbnailUrl;
        }
    }

    /** Numero di articoli nel carrello, per il contatore nell'intestazione. */
    public function cartCount(): int
    {
        try {
            return $this->app->get(CartService::class)->itemCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function placeholder(): string
    {
        return '/assets/placeholder.svg';
    }

    private function currentPath(): string
    {
        try {
            return $this->app->has(Request::class) ? $this->app->get(Request::class)->path() : '/';
        } catch (\Throwable) {
            return '/';
        }
    }
}
