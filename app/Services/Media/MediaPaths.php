<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Application;

/**
 * Unico punto che traduce una chiave di archiviazione in percorsi e URL.
 *
 * Struttura adottata:
 *
 *   storage/originals/{collezione}/{chiave}.{est}     originale privato
 *   public/uploads/{collezione}/{chiave}-{misura}.webp   versione pubblica
 *   public/uploads/{collezione}/{chiave}-{misura}.jpg    fallback pubblico
 *
 * Gli originali stanno fuori dalla cartella web: non sono raggiungibili via
 * HTTP nemmeno indovinandone il nome. Le versioni pubbliche sono file statici
 * serviti direttamente da Apache, senza passare da PHP: su hosting condiviso e
 * la differenza fra una galleria scattante e una lenta.
 */
final class MediaPaths
{
    public const COLLECTION_GALLERY = 'gallery';
    public const COLLECTION_NEWS = 'news';
    public const COLLECTION_EVENTS = 'events';
    public const COLLECTION_PRODUCTS = 'products';
    public const COLLECTION_MEMBERS = 'members';
    public const COLLECTION_PAGES = 'pages';
    public const COLLECTION_SOCIAL = 'social';

    public const SIZE_THUMB = 'thumb';
    public const SIZE_MEDIUM = 'medium';
    public const SIZE_LARGE = 'large';

    /** @var list<string> */
    public const COLLECTIONS = [
        self::COLLECTION_GALLERY,
        self::COLLECTION_NEWS,
        self::COLLECTION_EVENTS,
        self::COLLECTION_PRODUCTS,
        self::COLLECTION_MEMBERS,
        self::COLLECTION_PAGES,
        self::COLLECTION_SOCIAL,
    ];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Genera una nuova chiave: `AAAA/MM/<16 caratteri esadecimali>`.
     *
     * Il nome viene deciso dal server, mai dal browser. La suddivisione per
     * anno e mese evita cartelle con decine di migliaia di file, che su alcuni
     * filesystem degradano sensibilmente.
     */
    public function generateKey(): string
    {
        return date('Y/m') . '/' . bin2hex(random_bytes(8));
    }

    /**
     * Verifica che una chiave sia nella forma attesa.
     *
     * Ogni percorso costruito parte da qui: e il punto in cui si blocca il path
     * traversal, perché una chiave con `..` o con separatori anomali non passa.
     */
    public function isValidKey(string $key): bool
    {
        return preg_match('#^\d{4}/\d{2}/[a-f0-9]{16}$#', $key) === 1;
    }

    public function assertValidKey(string $key): void
    {
        if (! $this->isValidKey($key)) {
            throw new \InvalidArgumentException('Chiave di archiviazione non valida.');
        }
    }

    private function assertValidCollection(string $collection): void
    {
        if (! in_array($collection, self::COLLECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Collezione media sconosciuta: "%s".', $collection));
        }
    }

    // -----------------------------------------------------------------------
    //  Percorsi su disco
    // -----------------------------------------------------------------------

    /** Percorso dell'originale privato. */
    public function originalPath(string $collection, string $key, string $extension): string
    {
        $this->assertValidKey($key);
        $this->assertValidCollection($collection);

        return $this->app->storagePath(
            'originals' . DIRECTORY_SEPARATOR . $collection . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $key) . '.' . $this->safeExtension($extension)
        );
    }

    /** Percorso di una versione pubblica già elaborata. */
    public function publicPath(string $collection, string $key, string $size, string $format = 'webp'): string
    {
        $this->assertValidKey($key);
        $this->assertValidCollection($collection);

        return $this->app->publicPath(
            'uploads' . DIRECTORY_SEPARATOR . $collection . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $key) . '-' . $size . '.' . $this->safeExtension($format)
        );
    }

    /** URL pubblico di una versione elaborata. */
    public function url(string $collection, string $key, string $size = self::SIZE_MEDIUM, string $format = 'webp'): string
    {
        if (! $this->isValidKey($key) || ! in_array($collection, self::COLLECTIONS, true)) {
            return $this->placeholderUrl();
        }

        return sprintf('/uploads/%s/%s-%s.%s', $collection, $key, $size, $this->safeExtension($format));
    }

    /**
     * Attributo `srcset` con le tre misure disponibili.
     *
     * Un telefono non deve scaricare l'immagine da 2000 pixel: il browser
     * sceglie la misura giusta e su rete mobile il risparmio e sostanziale.
     */
    public function srcset(string $collection, string $key, string $format = 'webp'): string
    {
        if (! $this->isValidKey($key)) {
            return '';
        }

        $sizes = (array) config('image.sizes', [
            self::SIZE_THUMB => 400,
            self::SIZE_MEDIUM => 1200,
            self::SIZE_LARGE => 2000,
        ]);

        $parts = [];

        foreach ($sizes as $name => $width) {
            $parts[] = $this->url($collection, $key, (string) $name, $format) . ' ' . (int) $width . 'w';
        }

        return implode(', ', $parts);
    }

    public function placeholderUrl(): string
    {
        return '/assets/placeholder.svg';
    }

    /** Verifica l'esistenza fisica di una versione pubblica. */
    public function publicFileExists(string $collection, string $key, string $size, string $format = 'webp'): bool
    {
        if (! $this->isValidKey($key)) {
            return false;
        }

        return is_file($this->publicPath($collection, $key, $size, $format));
    }

    // -----------------------------------------------------------------------
    //  Eliminazione
    // -----------------------------------------------------------------------

    /**
     * Rimuove originale e versioni pubbliche di una chiave.
     *
     * @return int Numero di file effettivamente eliminati.
     */
    public function deleteAll(string $collection, string $key, string $extension = 'jpg'): int
    {
        if (! $this->isValidKey($key) || ! in_array($collection, self::COLLECTIONS, true)) {
            return 0;
        }

        $deleted = 0;
        $candidates = [$this->originalPath($collection, $key, $extension)];

        // L'estensione dell'originale non e sempre nota con certezza: proviamo
        // tutte quelle ammesse invece di lasciare file orfani su disco.
        foreach (array_values((array) config('image.allowed_mime_types', [])) as $ext) {
            $candidates[] = $this->originalPath($collection, $key, (string) $ext);
        }

        foreach (array_keys((array) config('image.sizes', [])) as $size) {
            foreach (['webp', 'jpg'] as $format) {
                $candidates[] = $this->publicPath($collection, $key, (string) $size, $format);
            }
        }

        foreach (array_unique($candidates) as $path) {
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Crea la directory di destinazione di un file. */
    public function ensureDirectoryFor(string $filePath): bool
    {
        $directory = dirname($filePath);

        return is_dir($directory) || mkdir($directory, 0o755, true) || is_dir($directory);
    }

    /** Normalizza l'estensione: solo lettere e cifre, minuscole, massimo 5 caratteri. */
    private function safeExtension(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return $extension === '' ? 'jpg' : substr($extension, 0, 5);
    }
}
