<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\HtmlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Sanitizzazione dell'HTML dell'editor.
 *
 * La pulizia avviene in scrittura: a database finisce solo HTML gia sicuro.
 * Questi test sono la garanzia che un contenuto malevolo non arrivi mai alle
 * pagine pubbliche.
 */
final class HtmlSanitizerTest extends IntegrationTestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = self::app()->get(HtmlSanitizer::class);
    }

    #[Test]
    public function conserva_la_formattazione_utile(): void
    {
        $html = '<h2>Titolo</h2><p>Testo con <strong>grassetto</strong> e <em>corsivo</em>.</p>'
            . '<ul><li>Prima voce</li><li>Seconda voce</li></ul>';

        $clean = (string) $this->sanitizer->clean($html);

        $this->assertStringContainsString('<h2>', $clean);
        $this->assertStringContainsString('<strong>', $clean);
        $this->assertStringContainsString('<ul>', $clean);
        $this->assertStringContainsString('Prima voce', $clean);
    }

    #[Test]
    public function rimuove_gli_script(): void
    {
        $clean = (string) $this->sanitizer->clean('<p>Testo</p><script>alert("attacco")</script>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(', $clean);
        $this->assertStringContainsString('Testo', $clean);
    }

    #[Test]
    public function rimuove_gli_attributi_di_evento(): void
    {
        $clean = (string) $this->sanitizer->clean('<p onclick="rubaDati()">Testo innocuo</p>');

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('Testo innocuo', $clean);
    }

    #[Test]
    public function neutralizza_i_link_javascript(): void
    {
        $clean = (string) $this->sanitizer->clean('<a href="javascript:alert(1)">Clicca</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
    }

    #[Test]
    public function rimuove_iframe_e_form(): void
    {
        $clean = (string) $this->sanitizer->clean(
            '<iframe src="https://esterno.example"></iframe><form action="/x"><input name="password"></form><p>Resta</p>'
        );

        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('<form', $clean);
        $this->assertStringNotContainsString('<input', $clean);
        $this->assertStringContainsString('Resta', $clean);
    }

    #[Test]
    public function i_link_esterni_ricevono_rel_noopener(): void
    {
        $clean = (string) $this->sanitizer->clean('<a href="https://example.com">Esterno</a>');

        $this->assertStringContainsString('noopener', $clean);
        $this->assertStringContainsString('noreferrer', $clean);
    }

    #[Test]
    public function un_contenuto_vuoto_diventa_null(): void
    {
        $this->assertNull($this->sanitizer->clean(''));
        $this->assertNull($this->sanitizer->clean('   '));
        $this->assertNull($this->sanitizer->clean(null));
    }

    #[Test]
    public function stripTags_lascia_solo_testo(): void
    {
        $this->assertSame('Testo semplice', $this->sanitizer->stripTags('<p>Testo <b>semplice</b></p>'));
    }
}
