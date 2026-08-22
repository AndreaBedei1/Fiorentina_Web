<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\View\TwigExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Lo stemma della Fiorentina come decorazione.
 *
 * Il file dello stemma non sta nel repository come sorgente del codice: e un
 * marchio registrato, e il permesso di usarlo appartiene al gruppo. Il sito
 * deve quindi funzionare in entrambi i mondi - con il file e senza - e questo
 * e cio che si verifica qui.
 *
 * I test lavorano su public/assets/, che e cartella di compilazione: qualunque
 * cosa succeda, `npm run build` la rigenera da resources/static/. Per sicurezza
 * i file veri vengono comunque messi da parte e rimessi al loro posto.
 */
final class DecorazioniTest extends IntegrationTestCase
{
    private const ESTENSIONI = ['svg', 'png', 'webp'];

    /** @var list<string> File di prova creati dal test. */
    private array $creati = [];

    /** @var array<string, string> File veri messi da parte: originale => copia. */
    private array $daParte = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ESTENSIONI as $estensione) {
            $percorso = $this->percorso($estensione);

            // Avanzo di un test interrotto a meta: si rimette a posto e basta.
            if (is_file($percorso . '.daparte')) {
                @rename($percorso . '.daparte', $percorso);
            }

            if (is_file($percorso)) {
                rename($percorso, $percorso . '.daparte');
                $this->daParte[$percorso] = $percorso . '.daparte';
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->creati as $percorso) {
            @unlink($percorso);
        }

        foreach ($this->daParte as $originale => $copia) {
            @rename($copia, $originale);
        }

        $this->creati = [];
        $this->daParte = [];

        parent::tearDown();
    }

    #[Test]
    public function senza_il_file_lo_stemma_non_viene_disegnato(): void
    {
        self::assertNull($this->estensione()->stemmaFiorentina());
    }

    #[Test]
    public function con_il_file_lo_stemma_ha_un_indirizzo_pubblico(): void
    {
        $this->scriviStemma('svg');

        self::assertSame('/assets/stemma-fiorentina.svg', $this->estensione()->stemmaFiorentina());
    }

    #[Test]
    public function va_bene_anche_un_png(): void
    {
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
}
