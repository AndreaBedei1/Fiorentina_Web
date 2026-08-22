<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\View\TwigExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Lo stemma della Fiorentina come decorazione.
 *
 * Il file dello stemma non sta nel repository: e un marchio registrato e il
 * permesso di usarlo appartiene al gruppo. Il sito deve quindi funzionare in
 * entrambi i mondi - con il file e senza - e questo e cio che si verifica qui.
 */
final class DecorazioniTest extends IntegrationTestCase
{
    /** @var list<string> File creati dal test, da togliere alla fine. */
    private array $creati = [];

    protected function tearDown(): void
    {
        foreach ($this->creati as $percorso) {
            @unlink($percorso);
        }

        $this->creati = [];

        parent::tearDown();
    }

    #[Test]
    public function senza_il_file_lo_stemma_non_viene_disegnato(): void
    {
        $this->togliOgniStemma();

        self::assertNull($this->estensione()->stemmaFiorentina());
    }

    #[Test]
    public function con_il_file_lo_stemma_ha_un_indirizzo_pubblico(): void
    {
        $this->togliOgniStemma();
        $this->scriviStemma('svg');

        self::assertSame('/assets/stemma-fiorentina.svg', $this->estensione()->stemmaFiorentina());
    }

    #[Test]
    public function va_bene_anche_un_png_o_un_webp(): void
    {
        $this->togliOgniStemma();
        $this->scriviStemma('png');

        self::assertSame('/assets/stemma-fiorentina.png', $this->estensione()->stemmaFiorentina());
    }

    /**
     * Con piu formati presenti vince l'svg.
     *
     * E il formato giusto per un marchio: nitido a ogni ingrandimento e
     * leggero, mentre il png esiste solo come ripiego.
     */
    #[Test]
    public function fra_piu_formati_vince_svg(): void
    {
        $this->togliOgniStemma();
        $this->scriviStemma('png');
        $this->scriviStemma('svg');

        self::assertSame('/assets/stemma-fiorentina.svg', $this->estensione()->stemmaFiorentina());
    }

    private function estensione(): TwigExtension
    {
        return self::app()->get(TwigExtension::class);
    }

    private function percorso(string $estensione): string
    {
        return self::app()->basePath('public/assets/stemma-fiorentina.' . $estensione);
    }

    private function scriviStemma(string $estensione): void
    {
        $percorso = $this->percorso($estensione);

        if (! is_dir(dirname($percorso))) {
            mkdir(dirname($percorso), 0775, true);
        }

        file_put_contents($percorso, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>');
        $this->creati[] = $percorso;
    }

    /** Il sito reale potrebbe averlo gia: si parte sempre da un tavolo sgombro. */
    private function togliOgniStemma(): void
    {
        foreach (['svg', 'png', 'webp'] as $estensione) {
            if (is_file($this->percorso($estensione))) {
                self::markTestSkipped('Lo stemma e gia installato: il test non tocca i file veri.');
            }
        }
    }
}
