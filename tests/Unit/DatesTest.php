<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\Dates;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Formattazione delle date in italiano. */
final class DatesTest extends TestCase
{
    #[Test]
    public function formatta_le_date_in_italiano(): void
    {
        $data = new DateTimeImmutable('2026-08-19 15:30:00');

        $this->assertSame('19 agosto 2026', Dates::long($data));
        $this->assertSame('19 ago 2026', Dates::short($data));
        $this->assertSame('19/08/2026', Dates::numeric($data));
        $this->assertSame('19/08/2026 15:30', Dates::numericWithTime($data));
        $this->assertSame('15:30', Dates::time($data));
        $this->assertSame('mercoledi 19 agosto 2026', Dates::longWithWeekday($data));
        $this->assertSame('19 agosto 2026 alle 15:30', Dates::longWithTime($data));
    }

    #[Test]
    public function produce_date_iso_per_i_dati_strutturati(): void
    {
        $data = new DateTimeImmutable('2026-08-19 15:30:00');

        $this->assertStringStartsWith('2026-08-19T15:30:00', Dates::iso($data));
        $this->assertSame('2026-08-19', Dates::isoDate($data));
    }

    #[Test]
    public function i_valori_non_validi_non_generano_errori(): void
    {
        // Le colonne DATETIME possono contenere null o zeri: la formattazione
        // deve restituire una stringa vuota, non far esplodere la pagina.
        $this->assertSame('', Dates::long(null));
        $this->assertSame('', Dates::long(''));
        $this->assertSame('', Dates::long('0000-00-00 00:00:00'));
        $this->assertNull(Dates::parse('non-una-data'));
    }

    #[Test]
    public function unisce_data_e_ora_dei_campi_html(): void
    {
        $this->assertSame('2026-08-19 21:00:00', Dates::combineToDatabase('2026-08-19', '21:00'));

        // Senza orario si assume mezzanotte.
        $this->assertSame('2026-08-19 00:00:00', Dates::combineToDatabase('2026-08-19', null));

        // Senza data non c e nulla da salvare.
        $this->assertNull(Dates::combineToDatabase(null, '21:00'));
        $this->assertNull(Dates::combineToDatabase('', '21:00'));
    }

    #[Test]
    public function le_date_relative_usano_il_plurale_corretto(): void
    {
        $adesso = new DateTimeImmutable('2026-08-19 12:00:00');

        $this->assertSame('1 giorno fa', Dates::relative(new DateTimeImmutable('2026-08-18 12:00:00'), $adesso));
        $this->assertSame('3 giorni fa', Dates::relative(new DateTimeImmutable('2026-08-16 12:00:00'), $adesso));
        $this->assertSame('fra 2 ore', Dates::relative(new DateTimeImmutable('2026-08-19 14:00:00'), $adesso));
        $this->assertSame('1 ora fa', Dates::relative(new DateTimeImmutable('2026-08-19 11:00:00'), $adesso));
    }

    #[Test]
    public function riconosce_oggi_e_il_passato(): void
    {
        $adesso = new DateTimeImmutable('2026-08-19 12:00:00');

        $this->assertTrue(Dates::isToday(new DateTimeImmutable('2026-08-19 23:00:00'), $adesso));
        $this->assertFalse(Dates::isToday(new DateTimeImmutable('2026-08-20 01:00:00'), $adesso));
        $this->assertTrue(Dates::isPast(new DateTimeImmutable('2026-08-18'), $adesso));
        $this->assertFalse(Dates::isPast(new DateTimeImmutable('2026-08-20'), $adesso));
    }
}
