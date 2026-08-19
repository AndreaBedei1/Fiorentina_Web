<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Regole di robustezza delle password. */
final class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function accetta_una_password_robusta(): void
    {
        // Valore d'esempio, non una credenziale: serve solo a mostrare una
        // combinazione che soddisfa lunghezza e varieta richieste.
        $robusta = 'esempio-di-password-lunga-2026';

        $this->assertNull(PasswordPolicy::check($robusta));
        $this->assertTrue(PasswordPolicy::isValid($robusta));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function passwordDeboli(): array
    {
        return [
            ['', 'vuota'],
            ['corta1A!', 'troppo corta'],
            ['tuttominuscolo', 'una sola categoria'],
            ['soltantolettereeminuscole', 'nessun numero ne maiuscola'],
            ['12345678901234', 'solo cifre'],
            ['password1234', 'solo minuscole e cifre'],
        ];
    }

    #[Test]
    #[DataProvider('passwordDeboli')]
    public function rifiuta_le_password_deboli(string $password, string $motivo): void
    {
        $this->assertNotNull(
            PasswordPolicy::check($password),
            sprintf('La password "%s" (%s) sarebbe dovuta essere rifiutata.', $password, $motivo),
        );
    }

    #[Test]
    public function conta_correttamente_le_categorie_di_caratteri(): void
    {
        $this->assertSame(1, PasswordPolicy::countCharacterClasses('soltantominuscole'));
        $this->assertSame(2, PasswordPolicy::countCharacterClasses('MinuscoleEMaiuscole'));
        $this->assertSame(3, PasswordPolicy::countCharacterClasses('MinuscoleMaiuscole1'));
        $this->assertSame(4, PasswordPolicy::countCharacterClasses('MinuscoleMaiuscole1!'));
    }

    #[Test]
    public function rifiuta_le_password_esageratamente_lunghe(): void
    {
        // Un limite superiore evita che una stringa enorme faccia lavorare
        // inutilmente l algoritmo di hashing.
        $this->assertNotNull(PasswordPolicy::check(str_repeat('Aa1!', 100)));
    }
}
