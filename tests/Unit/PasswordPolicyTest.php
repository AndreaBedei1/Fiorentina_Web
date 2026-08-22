<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regole di robustezza delle password.
 *
 * Almeno otto caratteri, e dentro una minuscola, una maiuscola e una cifra.
 */
final class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function accetta_una_password_a_regola(): void
    {
        // Valore d'esempio, non una credenziale: mostra solo una combinazione
        // che soddisfa lunghezza e varieta richieste.
        $buona = 'Trasferta2026';

        $this->assertNull(PasswordPolicy::check($buona));
        $this->assertTrue(PasswordPolicy::isValid($buona));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function passwordDeboli(): array
    {
        return [
            ['', 'vuota'],
            ['Ab1cde', 'sette caratteri, uno in meno del minimo'],
            ['tuttominuscolo', 'nessuna maiuscola e nessun numero'],
            ['TUTTOMAIUSCOLO1', 'nessuna minuscola'],
            ['Soltantolettere', 'nessun numero'],
            ['12345678901234', 'solo cifre'],
            ['password1234', 'nessuna maiuscola'],
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

    /** Otto caratteri esatti bastano, sette no. */
    #[Test]
    public function il_minimo_e_otto_caratteri(): void
    {
        $this->assertNull(PasswordPolicy::check('Curva12a'));
        $this->assertNotNull(PasswordPolicy::check('Curva1a'));
    }

    /**
     * Il messaggio dice cosa manca, non ripete la regola.
     *
     * "Combina almeno tre categorie" e vero ma non aiuta: chi sta scrivendo
     * deve rileggere la regola e confrontarla con quello che ha digitato.
     * "Manca una lettera maiuscola" si corregge senza pensarci.
     */
    #[Test]
    public function dice_che_cosa_manca(): void
    {
        $this->assertSame(['una lettera maiuscola'], PasswordPolicy::mancanti('trasferta2026'));
        $this->assertSame(['un numero'], PasswordPolicy::mancanti('Trasferta'));
        $this->assertSame([], PasswordPolicy::mancanti('Trasferta1'));

        $this->assertSame(
            'Alla password manca una lettera maiuscola e un numero.',
            PasswordPolicy::check('trasferta'),
        );
    }

    #[Test]
    public function rifiuta_le_password_esageratamente_lunghe(): void
    {
        // Un limite superiore evita che una stringa enorme faccia lavorare
        // inutilmente l algoritmo di hashing.
        $this->assertNotNull(PasswordPolicy::check(str_repeat('Aa1!', 100)));
    }
}
