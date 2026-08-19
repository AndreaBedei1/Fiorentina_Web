<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use App\Services\AuthService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/** Permessi dei due ruoli. */
final class AuthorizationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->startSession();
        $this->resetSession();
    }

    #[Test]
    public function l_amministratore_gestisce_i_contenuti_ma_non_gli_account(): void
    {
        $user = $this->createUser(['email' => 'admin@example.test', 'password' => 'PasswordDiProva2026!']);

        $auth = self::app()->make(AuthService::class);
        $auth->login($user);

        // Contenuti: si.
        $this->assertTrue($auth->can('news.manage'));
        $this->assertTrue($auth->can('events.manage'));
        $this->assertTrue($auth->can('gallery.manage'));
        $this->assertTrue($auth->can('products.manage'));
        $this->assertTrue($auth->can('orders.manage'));
        $this->assertTrue($auth->can('pages.manage'));

        // Amministrazione: no.
        $this->assertFalse($auth->can('admins.manage'));
        $this->assertFalse($auth->can('settings.manage'));
        $this->assertFalse($auth->can('audit.view'));
    }

    #[Test]
    public function il_super_amministratore_puo_tutto(): void
    {
        $user = $this->createSuperAdmin(['email' => 'super@example.test', 'password' => 'PasswordDiProva2026!']);

        $auth = self::app()->make(AuthService::class);
        $auth->login($user);

        foreach (AuthService::allPermissions() as $permission) {
            $this->assertTrue($auth->can($permission), sprintf('Permesso mancante: %s', $permission));
        }
    }

    #[Test]
    public function chi_non_ha_effettuato_l_accesso_non_ha_alcun_permesso(): void
    {
        $auth = self::app()->make(AuthService::class);

        $this->assertFalse($auth->check());

        foreach (AuthService::allPermissions() as $permission) {
            $this->assertFalse($auth->can($permission));
        }
    }

    #[Test]
    public function la_mappa_dei_permessi_distingue_i_due_ruoli(): void
    {
        $admin = AuthService::permissionsFor(User::ROLE_ADMIN);
        $superAdmin = AuthService::permissionsFor(User::ROLE_SUPER_ADMIN);

        $this->assertNotContains('admins.manage', $admin);
        $this->assertContains('admins.manage', $superAdmin);
        $this->assertGreaterThan(count($admin), count($superAdmin));
    }
}
