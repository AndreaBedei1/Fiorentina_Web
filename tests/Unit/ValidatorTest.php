<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validazione lato server.
 *
 * L'attenzione qui e su un dettaglio che sembra minore e non lo e: una regola
 * che controlla il valore ma non lo conserva fra i dati validati produce un
 * guasto silenzioso. Il controller legge da `validate()`, non trova il campo,
 * ricade sul valore predefinito e nessuno se ne accorge finche qualcuno non
 * nota che le notizie restano bozze qualunque cosa si scelga.
 */
final class ValidatorTest extends TestCase
{
    #[Test]
    public function un_valore_ammesso_finisce_fra_i_dati_validati(): void
    {
        $validated = Validator::make(['status' => 'published'])
            ->in('status', ['draft', 'published', 'archived'], 'Lo stato')
            ->validate();

        self::assertArrayHasKey('status', $validated);
        self::assertSame('published', $validated['status']);
    }

    #[Test]
    public function un_valore_non_ammesso_viene_respinto(): void
    {
        $validator = Validator::make(['status' => 'pubblicata'])
            ->in('status', ['draft', 'published', 'archived'], 'Lo stato');

        self::assertTrue($validator->fails());

        $this->expectException(ValidationException::class);
        $validator->validate();
    }

    #[Test]
    public function un_campo_vuoto_resta_nullo_e_non_blocca(): void
    {
        $validated = Validator::make(['status' => ''])
            ->in('status', ['draft', 'published'], 'Lo stato')
            ->validate();

        // Il campo c'e ma vale null: il controller applichera il proprio
        // valore predefinito, che e il comportamento voluto.
        self::assertArrayHasKey('status', $validated);
        self::assertNull($validated['status']);
    }

    #[Test]
    public function le_altre_regole_conservano_il_valore_ripulito(): void
    {
        // Ordine reale d'uso: `required` ripulisce gli spazi, `email` normalizza
        // le maiuscole. Le regole si applicano in cascata sul valore gia
        // ripulito da quelle precedenti.
        $validated = Validator::make([
            'title' => '  Trasferta a Bologna  ',
            'email' => '  INFO@Example.TEST  ',
        ])
            ->required('title', 'Il titolo')
            ->required('email', 'L indirizzo email')
            ->email('email', 'L indirizzo email')
            ->validate();

        self::assertSame('Trasferta a Bologna', $validated['title']);
        self::assertSame('info@example.test', $validated['email']);
    }
}
