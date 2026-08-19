<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuthResult;
use App\Services\AuthService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationTestCase;

/**
 * Autenticazione degli amministratori.
 *
 * E il perimetro di sicurezza del sito: qui verifichiamo che entri solo chi
 * deve, che chi viene bloccato smetta davvero di poter entrare, e che i
 * messaggi non rivelino quali indirizzi email appartengono allo staff.
 */
final class AuthenticationTest extends IntegrationTestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->startSession();
        $this->resetSession();

        $this->auth = self::app()->get(AuthService::class);
    }

    #[Test]
    public function accetta_le_credenziali_corrette(): void
    {
        $user = $this->createUser([
            'email' => 'valido@example.test',
            'password' => Fixtures::PASSWORD,
        ]);

        $result = $this->auth->attempt('valido@example.test', Fixtures::PASSWORD);

        $this->assertTrue($result->successful);
        $this->assertSame($user->id, $result->user?->id);
    }

    #[Test]
    public function rifiuta_la_password_sbagliata(): void
    {
        $this->createUser(['email' => 'valido@example.test', 'password' => Fixtures::PASSWORD]);

        $result = $this->auth->attempt('valido@example.test', 'PasswordSbagliata2026!');

        $this->assertTrue($result->failed());
        $this->assertSame(AuthResult::REASON_INVALID, $result->reason);
    }

    #[Test]
    public function il_messaggio_di_errore_non_rivela_se_l_email_esiste(): void
    {
        $this->createUser(['email' => 'esiste@example.test', 'password' => Fixtures::PASSWORD]);

        $emailEsistente = $this->auth->attempt('esiste@example.test', 'sbagliata');
        $emailInesistente = $this->auth->attempt('non-esiste@example.test', 'sbagliata');

        // Stesso identico messaggio: e cio che impedisce di usare il modulo di
        // accesso per scoprire quali indirizzi appartengono agli amministratori.
        $this->assertSame($emailEsistente->message(), $emailInesistente->message());
    }

    #[Test]
    public function un_account_bloccato_non_puo_accedere(): void
    {
        $user = $this->createUser(['email' => 'bloccato@example.test', 'password' => Fixtures::PASSWORD]);

        self::app()->get(UserRepository::class)->block($user->id);

        $result = $this->auth->attempt('bloccato@example.test', Fixtures::PASSWORD);

        $this->assertTrue($result->failed());
        $this->assertSame(AuthResult::REASON_BLOCKED, $result->reason);
    }

    #[Test]
    public function un_account_senza_password_non_puo_accedere(): void
    {
        // E lo stato di un invito non ancora accettato.
        $this->createUser([
            'email' => 'invitato@example.test',
            'status' => User::STATUS_PENDING,
            'password_hash' => null,
        ]);

        $result = $this->auth->attempt('invitato@example.test', 'qualunque');

        $this->assertTrue($result->failed());
    }

    #[Test]
    public function dopo_troppi_tentativi_l_accesso_viene_bloccato(): void
    {
        $this->createUser(['email' => 'bersaglio@example.test', 'password' => Fixtures::PASSWORD]);

        $maxAttempts = self::app()->config()->int('security.rate_limits.login.max_attempts', 5);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->auth->attempt('bersaglio@example.test', 'sbagliata');
        }

        // Anche con la password giusta, ora deve essere respinto.
        $result = $this->auth->attempt('bersaglio@example.test', Fixtures::PASSWORD);

        $this->assertTrue($result->isThrottled());
        $this->assertStringContainsString('Troppi tentativi', $result->message());
    }

    #[Test]
    public function bloccare_un_account_chiude_le_sue_sessioni_attive(): void
    {
        $user = $this->createUser(['email' => 'attivo@example.test', 'password' => Fixtures::PASSWORD]);

        $this->auth->attempt('attivo@example.test', Fixtures::PASSWORD);
        $this->assertTrue($this->auth->check(), 'La sessione doveva risultare aperta.');

        // Il blocco imposta sessions_valid_after: le sessioni gia aperte
        // devono decadere subito, non alla prossima disconnessione.
        self::app()->get(UserRepository::class)->block($user->id);

        $fresh = self::app()->make(AuthService::class);

        $this->assertFalse($fresh->check(), 'La sessione doveva essere invalidata dal blocco.');
    }

    #[Test]
    public function il_cambio_password_invalida_le_altre_sessioni(): void
    {
        $user = $this->createUser(['email' => 'cambio@example.test', 'password' => Fixtures::PASSWORD]);

        $this->auth->attempt('cambio@example.test', Fixtures::PASSWORD);
        $this->assertTrue($this->auth->check());

        /*
         * La colonna sessions_valid_after ha granularita di un secondo: una
         * sessione vale se e stata aperta PRIMA del cambio password. Qui
         * arretriamo di qualche secondo l istante di accesso per riprodurre una
         * cronologia realistica, invece di far coincidere i due eventi nello
         * stesso secondo.
         */
        $_SESSION['auth_login_at'] = time() - 5;

        $hash = self::app()->get(\App\Core\Security\Hash::class);
        self::app()->get(UserRepository::class)->updatePassword($user->id, $hash->make(Fixtures::PASSWORD_NUOVA));

        $fresh = self::app()->make(AuthService::class);

        $this->assertFalse($fresh->check());
    }

    #[Test]
    public function la_disconnessione_chiude_la_sessione(): void
    {
        $this->createUser(['email' => 'uscita@example.test', 'password' => Fixtures::PASSWORD]);

        $this->auth->attempt('uscita@example.test', Fixtures::PASSWORD);
        $this->assertTrue($this->auth->check());

        $this->auth->logout();

        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->user());
    }

    #[Test]
    public function i_tentativi_di_accesso_vengono_registrati(): void
    {
        $this->createUser(['email' => 'tracciato@example.test', 'password' => Fixtures::PASSWORD]);

        $this->auth->attempt('tracciato@example.test', 'sbagliata');
        $this->auth->attempt('tracciato@example.test', Fixtures::PASSWORD);

        $falliti = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND email = ?',
            ['tracciato@example.test'],
        );
        $riusciti = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE successful = 1 AND email = ?',
            ['tracciato@example.test'],
        );

        $this->assertSame(1, $falliti);
        $this->assertSame(1, $riusciti);
    }
}
