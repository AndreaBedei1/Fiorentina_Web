<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/** Limitatore di frequenza usato da accesso, modulo contatti e invio ordini. */
final class RateLimiterTest extends IntegrationTestCase
{
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->limiter = self::app()->get(RateLimiter::class);
    }

    #[Test]
    public function conta_i_tentativi_nella_finestra(): void
    {
        $this->assertSame(0, $this->limiter->attempts('contact', '203.0.113.1'));

        $this->limiter->hit('contact', '203.0.113.1', 60);
        $this->limiter->hit('contact', '203.0.113.1', 60);

        $this->assertSame(2, $this->limiter->attempts('contact', '203.0.113.1'));
    }

    #[Test]
    public function scatta_il_blocco_al_raggiungimento_della_soglia(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->hit('login', 'utente@example.test', 15);
        }

        $this->assertTrue($this->limiter->tooManyAttempts('login', 'utente@example.test', 5));
        $this->assertFalse($this->limiter->tooManyAttempts('login', 'utente@example.test', 10));
    }

    #[Test]
    public function contatori_diversi_non_si_influenzano(): void
    {
        $this->limiter->hit('login', 'primo@example.test', 15);
        $this->limiter->hit('login', 'primo@example.test', 15);

        $this->assertSame(2, $this->limiter->attempts('login', 'primo@example.test'));
        $this->assertSame(0, $this->limiter->attempts('login', 'secondo@example.test'));
        $this->assertSame(0, $this->limiter->attempts('contact', 'primo@example.test'));
    }

    #[Test]
    public function l_azzeramento_libera_il_contatore(): void
    {
        $this->limiter->hit('login', 'utente@example.test', 15);
        $this->limiter->clear('login', 'utente@example.test');

        $this->assertSame(0, $this->limiter->attempts('login', 'utente@example.test'));
    }

    #[Test]
    public function l_identificatore_non_viene_salvato_in_chiaro(): void
    {
        $this->limiter->hit('login', 'riservato@example.test', 15);

        $chiavi = $this->db->column('SELECT bucket_key FROM rate_limits');

        // Le chiavi sono hash: una tabella tecnica non deve conservare
        // indirizzi email leggibili.
        foreach ($chiavi as $chiave) {
            $this->assertStringNotContainsString('riservato@example.test', (string) $chiave);
        }
    }

    #[Test]
    public function riporta_i_secondi_mancanti_alla_riapertura(): void
    {
        $this->limiter->hit('order', '203.0.113.5', 60);

        $secondi = $this->limiter->secondsUntilReset('order', '203.0.113.5');

        $this->assertGreaterThan(0, $secondi);
        $this->assertLessThanOrEqual(3600, $secondi);
    }
}
