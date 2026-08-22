<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Utilita sulle stringhe.
 *
 * Gli slug meritano attenzione: finiscono negli indirizzi pubblici e a
 * database, quindi devono essere stabili e prevedibili nel tempo.
 */
final class StrTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function slugAttesi(): array
    {
        return [
            ['Cena sociale 2026', 'cena-sociale-2026'],
            ['Trasferta a Bologna', 'trasferta-a-bologna'],
            ['Perche siamo cosi', 'perche-siamo-cosi'],
            ['Citta   con   spazi', 'citta-con-spazi'],
            ['Simboli!!! rimossi###', 'simboli-rimossi'],
            // La chiocciola viene traslitterata, non scartata: e il
            // comportamento di cocur/slugify e produce slug leggibili.
            ['Fiorentina @ Bologna', 'fiorentina-at-bologna'],
            ['  spazi ai bordi  ', 'spazi-ai-bordi'],
            ['UPPERCASE TITLE', 'uppercase-title'],
            ['gia-uno-slug', 'gia-uno-slug'],
        ];
    }

    #[Test]
    #[DataProvider('slugAttesi')]
    public function genera_slug_prevedibili(string $input, string $atteso): void
    {
        $this->assertSame($atteso, Str::slug($input));
    }

    #[Test]
    public function gli_accenti_diventano_lettere_semplici(): void
    {
        $slug = Str::slug('Citta perche cosi');

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    #[Test]
    public function uno_slug_vuoto_ricade_su_un_valore_predefinito(): void
    {
        // Uno slug vuoto renderebbe l indirizzo ambiguo: meglio un fallback.
        $this->assertSame('voce', Str::slug('!!!'));
        $this->assertSame('voce', Str::slug(''));
    }

    #[Test]
    public function lo_slug_non_supera_la_lunghezza_della_colonna(): void
    {
        $this->assertLessThanOrEqual(190, mb_strlen(Str::slug(str_repeat('parola ', 100))));
    }

    #[Test]
    public function il_troncamento_non_spezza_le_parole(): void
    {
        $risultato = Str::truncate('La coreografia del derby e stata preparata in sei mesi', 25);

        $this->assertLessThanOrEqual(28, mb_strlen($risultato));
        $this->assertStringEndsWith('...', $risultato);
        $this->assertStringNotContainsString('coreograf...', $risultato);
    }

    #[Test]
    public function il_testo_piu_corto_del_limite_resta_invariato(): void
    {
        $this->assertSame('Testo breve', Str::truncate('Testo breve', 100));
    }

    #[Test]
    public function l_estratto_rimuove_i_tag_html(): void
    {
        $estratto = Str::excerpt('<p>Primo <strong>paragrafo</strong>.</p><p>Secondo.</p>', 100);

        $this->assertStringNotContainsString('<', $estratto);
        $this->assertStringContainsString('Primo paragrafo', $estratto);
    }

    #[Test]
    public function gli_importi_seguono_la_convenzione_italiana(): void
    {
        $this->assertSame('1.234,50 €', Str::money(1234.5));
        $this->assertSame('18,00 €', Str::money(18));
        $this->assertSame('0,00 €', Str::money(0));
        $this->assertSame('7,00', Str::money(7, withSymbol: false));
    }

    #[Test]
    public function le_iniziali_servono_agli_avatar_segnaposto(): void
    {
        $this->assertSame('MB', Str::initials('Marco Bianchi'));
        $this->assertSame('M', Str::initials('Marco'));
        $this->assertSame('?', Str::initials('   '));
    }

    #[Test]
    public function le_email_nei_log_sono_mascherate(): void
    {
        // Nei log tecnici non deve finire un indirizzo leggibile per intero.
        $mascherata = Str::maskEmail('mario.rossi@example.it');

        $this->assertStringStartsWith('ma', $mascherata);
        $this->assertStringEndsWith('@example.it', $mascherata);
        $this->assertStringNotContainsString('mario.rossi', $mascherata);
    }
}
