<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Http\UploadedFile;

/**
 * Genera immagini dimostrative con GD.
 *
 * Perche generarle invece di includere file binari nel repository:
 *
 *  1. il repository resta leggero e senza materiale di dubbia provenienza;
 *  2. le immagini passano dalla vera pipeline di elaborazione, quindi il seed
 *     verifica per davvero ridimensionamento, conversione WebP e filigrana;
 *  3. sono chiaramente riconoscibili come segnaposto: nessuno rischia di
 *     lasciarle online per errore.
 */
final class DemoImageFactory
{
    /** Palette coerente con il design system. */
    private const PALETTES = [
        [[0x41, 0x21, 0x5f], [0x8151b8 >> 16 & 0xff, 0x8151b8 >> 8 & 0xff, 0x8151b8 & 0xff]],
        [[0x2c, 0x16, 0x40], [0xcd, 0x22, 0x47]],
        [[0x58, 0x2d, 0x84], [0xf0, 0x73, 0x87]],
        [[0x1a, 0x0d, 0x27], [0x6b, 0x3a, 0xa0]],
        [[0x7b, 0x16, 0x34], [0x41, 0x21, 0x5f]],
        [[0x41, 0x21, 0x5f], [0xd5, 0xce, 0xc3]],
    ];

    private string $temporaryDirectory;

    public function __construct(string $temporaryDirectory)
    {
        $this->temporaryDirectory = rtrim($temporaryDirectory, '/\\');

        if (! is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0o755, true);
        }
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagejpeg');
    }

    /**
     * Crea un file JPEG dimostrativo e lo restituisce come upload simulato.
     *
     * @param int $seed Determina colori e disposizione: lo stesso seme produce
     *                  sempre la stessa immagine, cosi il seed e riproducibile.
     */
    public function create(string $label, int $seed, int $width = 1600, int $height = 1067): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imageantialias($image, true);

        [$from, $to] = self::PALETTES[$seed % count(self::PALETTES)];

        $this->paintGradient($image, $width, $height, $from, $to);
        $this->paintShapes($image, $width, $height, $seed);
        $this->paintLabel($image, $width, $height, $label);

        $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'demo-' . $seed . '-' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($image, $path, 88);
        imagedestroy($image);

        return UploadedFile::fake($path, $this->fileNameFor($label));
    }

    /** Sfumatura verticale fra due colori. */
    private function paintGradient(\GdImage $image, int $width, int $height, array $from, array $to): void
    {
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);

            $color = imagecolorallocate(
                $image,
                (int) round($from[0] + ($to[0] - $from[0]) * $ratio),
                (int) round($from[1] + ($to[1] - $from[1]) * $ratio),
                (int) round($from[2] + ($to[2] - $from[2]) * $ratio),
            );

            imageline($image, 0, $y, $width, $y, $color);
        }
    }

    /**
     * Forme geometriche deterministiche.
     *
     * Nessun uso di rand(): la disposizione dipende solo dal seme, quindi
     * rilanciando il seed le immagini restano identiche.
     */
    private function paintShapes(\GdImage $image, int $width, int $height, int $seed): void
    {
        imagealphablending($image, true);

        $white = imagecolorallocatealpha($image, 255, 255, 255, 108);
        $accent = imagecolorallocatealpha($image, 0xcd, 0x22, 0x47, 96);

        // Bande diagonali che ricordano le gradinate.
        for ($i = 0; $i < 5; $i++) {
            $offset = (int) ($height * (0.42 + $i * 0.12)) + ($seed % 7) * 12;

            imagefilledpolygon($image, [
                0, $offset,
                $width, $offset - (int) ($height * 0.08),
                $width, $offset - (int) ($height * 0.055),
                0, $offset + (int) ($height * 0.025),
            ], $i % 2 === 0 ? $white : $accent);
        }

        // Cerchi sparsi, come una folla vista da lontano.
        for ($i = 0; $i < 42; $i++) {
            $x = (int) (($i * 137 + $seed * 61) % $width);
            $y = (int) ($height * 0.45 + (($i * 89 + $seed * 37) % (int) ($height * 0.5)));
            $radius = 6 + (($i + $seed) % 14);

            imagefilledellipse($image, $x, $y, $radius, $radius, $i % 3 === 0 ? $accent : $white);
        }
    }

    /** Etichetta "IMMAGINE DIMOSTRATIVA" con il testo passato. */
    private function paintLabel(\GdImage $image, int $width, int $height, string $label): void
    {
        $font = 5; // font interno di GD: piccolo ma sempre disponibile
        $white = imagecolorallocate($image, 0xf3, 0xf1, 0xed);
        $panel = imagecolorallocatealpha($image, 0x1a, 0x0d, 0x27, 40);

        $lines = ['IMMAGINE DIMOSTRATIVA', mb_strtoupper($this->asciiOnly($label))];

        $lineHeight = imagefontheight($font);
        $panelHeight = $lineHeight * count($lines) + 28;
        $panelTop = (int) (($height - $panelHeight) / 2);

        imagefilledrectangle($image, 0, $panelTop, $width, $panelTop + $panelHeight, $panel);

        foreach ($lines as $index => $line) {
            $textWidth = imagefontwidth($font) * strlen($line);
            $x = (int) (($width - $textWidth) / 2);
            $y = $panelTop + 14 + $index * $lineHeight;

            imagestring($image, $font, $x, $y, $line, $white);
        }
    }

    /** Il font interno di GD gestisce solo ASCII. */
    private function asciiOnly(string $value): string
    {
        $value = strtr($value, [
            'a' => 'a', 'e' => 'e', 'i' => 'i', 'o' => 'o', 'u' => 'u',
        ]);

        return preg_replace('/[^A-Za-z0-9 \-]/', '', $value) ?? $value;
    }

    private function fileNameFor(string $label): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $label) ?? 'demo');

        return trim($slug, '-') . '.jpg';
    }

    /** Ripulisce i file temporanei creati durante il seed. */
    public function cleanup(): void
    {
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'demo-*.jpg') ?: [] as $file) {
            @unlink($file);
        }
    }
}
