<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Media\WatermarkService;
use App\Services\SettingsService;

/**
 * Pannello impostazioni. Riservato ai super amministratori.
 *
 * Contiene esclusivamente valori non sensibili. Le chiavi API e le credenziali
 * SMTP restano nel file .env: esporle in un form significherebbe salvarle in
 * chiaro a database e mostrarle a chiunque abbia accesso al pannello.
 */
final class SettingsController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->render('admin/settings.twig', [
            'seo' => $this->seo('Impostazioni')->withNoindex(),
            'groups' => $this->settings->groupedForAdmin(),
            'groupLabels' => SettingsService::groupLabels(),
            'watermarkPositions' => WatermarkService::positionOptions(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->requireSuperAdmin();

        $values = [];

        foreach (SettingsService::DEFINITIONS as $key => $definition) {
            // Le caselle di spunta non compaiono nella richiesta quando sono
            // deselezionate: vanno gestite esplicitamente.
            if ($definition['type'] === 'bool') {
                $values[$key] = $request->bool($key) ? '1' : '0';

                continue;
            }

            if (! $request->has($key)) {
                continue;
            }

            $value = trim((string) $request->post($key, ''));

            $values[$key] = match ($definition['type']) {
                'int' => (string) (int) $value,
                'float' => (string) (float) str_replace(',', '.', $value),
                'email' => mb_strtolower($value),
                default => $value,
            };
        }

        $this->settings->updateMany($values, $user->id);

        $this->audit->log(
            AuditLogger::SETTINGS_UPDATED,
            'settings',
            null,
            sprintf('%d impostazioni aggiornate', count($values)),
            ['keys' => array_keys($values)],
        );

        $this->success('Impostazioni salvate.');

        return $this->redirectToRoute('admin.settings.index');
    }
}
