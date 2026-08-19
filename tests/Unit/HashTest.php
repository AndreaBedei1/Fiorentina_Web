<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Hash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

/**
 * Hashing delle password.
 *
 * E la difesa che conta di piu: se un giorno il database finisse nelle mani
 * sbagliate, questi test sono la garanzia che le password non siano leggibili.
 */
final class HashTest extends TestCase
{
    private Hash $hash;

    protected function setUp(): void
    {
        // Parametri ridotti: qui verifichiamo il comportamento, non la lentezza.
        $this->hash = new Hash(bcryptCost: 4, argonMemoryCost: 8192, argonTimeCost: 1, argonThreads: 1);
    }

    #[Test]
    public function la_password_non_compare_mai_in_chiaro_nell_hash(): void
    {
        $password = Fixtures::PASSWORD;
        $hash = $this->hash->make($password);

        $this->assertStringNotContainsString($password, $hash);
        $this->assertNotSame($password, $hash);
        $this->assertGreaterThan(40, strlen($hash));
    }

    #[Test]
    public function usa_argon2id_quando_disponibile_altrimenti_bcrypt(): void
    {
        $hash = $this->hash->make(Fixtures::PASSWORD);

        if ($this->hash->supportsArgon2id()) {
            $this->assertStringStartsWith('$argon2id$', $hash);
            $this->assertSame('argon2id', $this->hash->algorithmName());
        } else {
            $this->assertStringStartsWith('$2y$', $hash);
            $this->assertSame('bcrypt', $this->hash->algorithmName());
        }
    }

    #[Test]
    public function la_verifica_accetta_la_password_giusta_e_rifiuta_le_altre(): void
    {
        $hash = $this->hash->make(Fixtures::PASSWORD);

        $this->assertTrue($this->hash->verify(Fixtures::PASSWORD, $hash));

        // Le varianti sono derivate dalla password giusta invece che scritte a
        // mano: cosi non possono restare indietro se il valore cambia.
        $this->assertFalse($this->hash->verify(mb_strtoupper(Fixtures::PASSWORD), $hash), 'maiuscole e minuscole devono contare');
        $this->assertFalse($this->hash->verify(mb_substr(Fixtures::PASSWORD, 0, -1), $hash), 'un carattere in meno non deve bastare');
        $this->assertFalse($this->hash->verify(Fixtures::PASSWORD . 'x', $hash), 'un carattere in piu non deve bastare');
        $this->assertFalse($this->hash->verify('', $hash));
    }

    #[Test]
    public function due_hash_della_stessa_password_sono_diversi(): void
    {
        // Il sale casuale rende inutili le tabelle precalcolate: senza,
        // password identiche produrrebbero hash identici.
        $primo = $this->hash->make(Fixtures::PASSWORD);
        $secondo = $this->hash->make(Fixtures::PASSWORD);

        $this->assertNotSame($primo, $secondo);
        $this->assertTrue($this->hash->verify(Fixtures::PASSWORD, $primo));
        $this->assertTrue($this->hash->verify(Fixtures::PASSWORD, $secondo));
    }

    #[Test]
    public function la_verifica_su_hash_vuoto_fallisce_senza_errori(): void
    {
        // Succede quando un account e stato invitato ma non ha ancora scelto
        // la password: non deve generare eccezioni ne autenticare nessuno.
        $this->assertFalse($this->hash->verify('qualunque', ''));
    }

    #[Test]
    public function riconosce_gli_hash_da_rigenerare(): void
    {
        $debole = password_hash(Fixtures::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $forte = new Hash(bcryptCost: 12, argonMemoryCost: 65536, argonTimeCost: 4, argonThreads: 2);

        $this->assertTrue($forte->needsRehash($debole));
    }
}
