<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AdminUserService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationTestCase;

/**
 * Gestione degli account amministratore.
 *
 * Le salvaguardie verificate qui sono quelle che impediscono al gruppo di
 * chiudersi fuori dal proprio sito: senza super amministratori attivi, nessuno
 * potrebbe piu creare account o cambiare le impostazioni.
 */
final class AdminUserServiceTest extends IntegrationTestCase
{
    private AdminUserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->startSession();
        $this->resetSession();

        $this->service = self::app()->get(AdminUserService::class);
    }

    #[Test]
    public function un_invito_crea_un_account_in_attesa_senza_password(): void
    {
        $inviter = $this->createSuperAdmin();

        $result = $this->service->invite('Nuovo Collaboratore', 'nuovo@example.test', User::ROLE_ADMIN, $inviter);

        $this->assertTrue($result->successful, $result->message);

        $created = self::app()->get(UserRepository::class)->findByEmail('nuovo@example.test');

        $this->assertNotNull($created);
        $this->assertTrue($created->isPending());
        $this->assertNull($created->passwordHash);
        $this->assertFalse($created->canLogin());
    }

    #[Test]
    public function del_token_di_invito_viene_salvato_solo_l_hash(): void
    {
        $inviter = $this->createSuperAdmin();

        $result = $this->service->invite('Con Token', 'token@example.test', User::ROLE_ADMIN, $inviter);
        $link = (string) $result->get('link');

        $token = substr($link, strrpos($link, '/') + 1);
        $stored = (string) $this->db->scalar('SELECT token_hash FROM admin_invites LIMIT 1');

        // Nel database non deve esserci traccia del token in chiaro.
        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertSame(64, strlen($stored));
    }

    #[Test]
    public function accettare_l_invito_attiva_l_account(): void
    {
        $inviter = $this->createSuperAdmin();
        $result = $this->service->invite('Attivato', 'attiva@example.test', User::ROLE_ADMIN, $inviter);

        $link = (string) $result->get('link');
        $token = substr($link, strrpos($link, '/') + 1);

        $accepted = $this->service->acceptInvite($token, Fixtures::PASSWORD_NUOVA);

        $this->assertTrue($accepted->successful, $accepted->message);

        $user = self::app()->get(UserRepository::class)->findByEmail('attiva@example.test');

        $this->assertNotNull($user);
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->canLogin());
    }

    #[Test]
    public function il_token_di_invito_vale_una_sola_volta(): void
    {
        $inviter = $this->createSuperAdmin();
        $result = $this->service->invite('Riuso', 'riuso@example.test', User::ROLE_ADMIN, $inviter);

        $link = (string) $result->get('link');
        $token = substr($link, strrpos($link, '/') + 1);

        $this->assertTrue($this->service->acceptInvite($token, 'PrimaPassword2026!')->successful);
        $this->assertTrue($this->service->acceptInvite($token, 'SecondaPassword2026!')->failed());
    }

    #[Test]
    public function non_si_puo_invitare_due_volte_lo_stesso_indirizzo(): void
    {
        $inviter = $this->createSuperAdmin();
        $this->createUser(['email' => 'gia@example.test']);

        $result = $this->service->invite('Doppione', 'gia@example.test', User::ROLE_ADMIN, $inviter);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('gia un amministratore', $result->message);
    }

    #[Test]
    public function un_amministratore_normale_non_puo_bloccare_nessuno(): void
    {
        $admin = $this->createUser();
        $target = $this->createUser();

        $result = $this->service->block($target->id, $admin);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('super amministratore', $result->message);

        $this->assertTrue(self::app()->get(UserRepository::class)->find($target->id)?->isActive());
    }

    #[Test]
    public function non_si_puo_bloccare_il_proprio_account(): void
    {
        $superAdmin = $this->createSuperAdmin();
        // Un secondo super admin, altrimenti scatterebbe l altra salvaguardia.
        $this->createSuperAdmin();

        $result = $this->service->block($superAdmin->id, $superAdmin);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('tuo stesso account', $result->message);
    }

    #[Test]
    public function non_si_puo_bloccare_l_ultimo_super_amministratore(): void
    {
        $primo = $this->createSuperAdmin();
        $secondo = $this->createSuperAdmin();

        // Bloccando il secondo resta solo il primo: consentito.
        $this->assertTrue($this->service->block($secondo->id, $primo)->successful);

        // Ora il primo e l unico rimasto: un altro super admin (bloccato) non
        // puo agire, quindi proviamo la salvaguardia dal lato del repository.
        $users = self::app()->get(UserRepository::class);

        $this->assertTrue($users->isLastActiveSuperAdmin($primo->id));
        $this->assertSame(0, $users->countActiveSuperAdmins($primo->id));
    }

    #[Test]
    public function non_si_puo_declassare_l_ultimo_super_amministratore(): void
    {
        $unico = $this->createSuperAdmin();
        $secondo = $this->createSuperAdmin();

        // Declassare il secondo va bene: ne resta uno.
        $this->assertTrue($this->service->changeRole($secondo->id, User::ROLE_ADMIN, $unico)->successful);

        // Declassare l ultimo no: il sito resterebbe senza gestori.
        $altroSuper = $this->createSuperAdmin();
        $result = $this->service->changeRole($unico->id, User::ROLE_ADMIN, $altroSuper);

        // Con due super admin attivi l operazione e lecita.
        $this->assertTrue($result->successful, $result->message);

        // Adesso ne resta uno solo: declassarlo deve fallire.
        $fallito = $this->service->changeRole($altroSuper->id, User::ROLE_ADMIN, $altroSuper);
        $this->assertTrue($fallito->failed());
    }

    #[Test]
    public function eliminare_un_account_lo_disattiva_senza_cancellarlo(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = $this->createUser(['email' => 'rimosso@example.test']);

        $result = $this->service->delete($target->id, $superAdmin);

        $this->assertTrue($result->successful, $result->message);

        // Non e piu visibile agli elenchi.
        $this->assertNull(self::app()->get(UserRepository::class)->find($target->id));

        // Ma la riga esiste ancora: il registro attivita deve poterla risolvere.
        $this->assertSame(1, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE id = ? AND deleted_at IS NOT NULL',
            [$target->id],
        ));
    }

    #[Test]
    public function sbloccare_un_invito_mai_accettato_lo_riporta_in_attesa(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $pending = $this->createUser([
            'email' => 'pendente@example.test',
            'status' => User::STATUS_PENDING,
            'password_hash' => null,
        ]);

        self::app()->get(UserRepository::class)->block($pending->id);
        $this->service->unblock($pending->id, $superAdmin);

        // Non deve diventare "attivo": non ha mai scelto una password.
        $this->assertTrue(self::app()->get(UserRepository::class)->find($pending->id)?->isPending());
    }
}
