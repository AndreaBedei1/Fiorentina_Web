<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\UploadedFile;
use App\Models\Album;
use App\Repositories\AlbumRepository;
use App\Repositories\PhotoRepository;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaPaths;
use App\Services\Media\PhotoService;
use App\Services\Media\UploadValidator;
use App\Services\Media\WatermarkService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Caricamento ed elaborazione delle fotografie.
 *
 * Sono i test piu importanti sul fronte upload: verificano che un file
 * mascherato da immagine non passi, che gli originali non finiscano nella
 * cartella pubblica e che la filigrana venga davvero applicata.
 */
final class MediaTest extends IntegrationTestCase
{
    /** @var list<string> File temporanei da ripulire a fine test. */
    private array $temporaryFiles = [];

    /** @var list<array{collection: string, key: string, extension: string}> */
    private array $storedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();

        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('Nessun driver immagini disponibile (gd o imagick).');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $paths = self::app()->get(MediaPaths::class);

        foreach ($this->storedKeys as $stored) {
            $paths->deleteAll($stored['collection'], $stored['key'], $stored['extension']);
        }

        parent::tearDown();
    }

    /** Crea un JPEG di prova e lo restituisce come upload simulato. */
    private function fakeImage(int $width = 1400, int $height = 900): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 0x41, 0x21, 0x5f));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), 200, 200, imagecolorallocate($image, 0xcd, 0x22, 0x47));

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test-' . bin2hex(random_bytes(6)) . '.jpg';
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        $this->temporaryFiles[] = $path;

        return UploadedFile::fake($path, 'fotografia-di-prova.jpg');
    }

    /** Crea un file non-immagine con estensione .jpg: il classico tentativo di aggirare i controlli. */
    private function fakeDisguisedFile(): UploadedFile
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'finta-' . bin2hex(random_bytes(6)) . '.jpg';
        file_put_contents($path, "<?php echo 'questo non deve mai essere accettato'; ?>");

        $this->temporaryFiles[] = $path;

        return UploadedFile::fake($path, 'innocua.jpg');
    }

    // -----------------------------------------------------------------------
    //  Validazione
    // -----------------------------------------------------------------------

    #[Test]
    public function accetta_una_immagine_valida(): void
    {
        $result = self::app()->get(UploadValidator::class)->validateImage($this->fakeImage());

        $this->assertTrue($result->valid, (string) $result->error);
        $this->assertSame('jpg', $result->extension);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertSame(1400, $result->width);
    }

    #[Test]
    public function rifiuta_un_file_php_rinominato_in_jpg(): void
    {
        // Il controllo non guarda l estensione ne il tipo dichiarato dal
        // browser: legge i primi byte del file. E la difesa che conta.
        $result = self::app()->get(UploadValidator::class)->validateImage($this->fakeDisguisedFile());

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('non e un formato immagine ammesso', (string) $result->error);
    }

    #[Test]
    public function rifiuta_i_file_troppo_pesanti(): void
    {
        $result = self::app()->get(UploadValidator::class)->validateImage($this->fakeImage(), maxBytes: 100);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('limite', (string) $result->error);
    }

    #[Test]
    public function il_nome_originale_viene_ripulito(): void
    {
        $file = UploadedFile::fake(__FILE__, '../../etc/passwd; rm -rf /.jpg');

        // Il nome del browser non deve poter costruire un percorso.
        $this->assertStringNotContainsString('..', $file->sanitizedClientName());
        $this->assertStringNotContainsString('/', $file->sanitizedClientName());
        $this->assertStringNotContainsString(';', $file->sanitizedClientName());
    }

    // -----------------------------------------------------------------------
    //  Percorsi
    // -----------------------------------------------------------------------

    #[Test]
    public function le_chiavi_generate_hanno_il_formato_atteso(): void
    {
        $paths = self::app()->get(MediaPaths::class);
        $key = $paths->generateKey();

        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{16}$#', $key);
        $this->assertTrue($paths->isValidKey($key));
    }

    #[Test]
    public function le_chiavi_malformate_vengono_rifiutate(): void
    {
        $paths = self::app()->get(MediaPaths::class);

        // Sono i tentativi tipici di path traversal.
        $this->assertFalse($paths->isValidKey('../../../etc/passwd'));
        $this->assertFalse($paths->isValidKey('2026/08/../../../secret'));
        $this->assertFalse($paths->isValidKey('2026/08/NONESADECIMALE!'));
        $this->assertFalse($paths->isValidKey(''));
        $this->assertFalse($paths->isValidKey('2026/08/troppocorta'));
    }

    #[Test]
    public function una_chiave_non_valida_produce_il_segnaposto_non_un_percorso(): void
    {
        $paths = self::app()->get(MediaPaths::class);

        $this->assertSame($paths->placeholderUrl(), $paths->url('gallery', '../../etc/passwd'));
    }

    #[Test]
    public function costruire_un_percorso_con_chiave_non_valida_solleva_eccezione(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::app()->get(MediaPaths::class)->originalPath('gallery', '../../evasione', 'jpg');
    }

    // -----------------------------------------------------------------------
    //  Elaborazione
    // -----------------------------------------------------------------------

    #[Test]
    public function l_elaborazione_produce_tutte_le_misure_in_webp_e_jpeg(): void
    {
        $paths = self::app()->get(MediaPaths::class);
        $processor = self::app()->get(ImageProcessor::class);

        $key = $paths->generateKey();
        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $key, 'extension' => 'jpg'];

        $processed = $processor->process(
            $this->fakeImage()->path(),
            MediaPaths::COLLECTION_GALLERY,
            $key,
            'jpg',
            applyWatermark: false,
        );

        $this->assertSame($key, $processed->key);
        $this->assertSame(1400, $processed->width);

        foreach (['thumb', 'medium', 'large'] as $size) {
            foreach (['webp', 'jpg'] as $format) {
                $this->assertFileExists(
                    $paths->publicPath(MediaPaths::COLLECTION_GALLERY, $key, $size, $format),
                    sprintf('Manca la versione %s in formato %s.', $size, $format),
                );
            }
        }
    }

    #[Test]
    public function l_originale_resta_fuori_dalla_cartella_pubblica(): void
    {
        $paths = self::app()->get(MediaPaths::class);
        $processor = self::app()->get(ImageProcessor::class);

        $key = $paths->generateKey();
        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $key, 'extension' => 'jpg'];

        $processor->process($this->fakeImage()->path(), MediaPaths::COLLECTION_GALLERY, $key, 'jpg');

        $originale = $paths->originalPath(MediaPaths::COLLECTION_GALLERY, $key, 'jpg');

        $this->assertFileExists($originale);

        // Il percorso dell originale non deve trovarsi dentro public/.
        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR,
            $originale,
            'Gli originali non devono essere raggiungibili via HTTP.',
        );
        $this->assertStringContainsString('storage', $originale);
    }

    #[Test]
    public function le_versioni_pubbliche_sono_ridimensionate(): void
    {
        $paths = self::app()->get(MediaPaths::class);
        $processor = self::app()->get(ImageProcessor::class);

        $key = $paths->generateKey();
        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $key, 'extension' => 'jpg'];

        $processor->process($this->fakeImage(2400, 1600)->path(), MediaPaths::COLLECTION_GALLERY, $key, 'jpg');

        $sizes = (array) self::app()->config()->array('image.sizes');

        foreach ($sizes as $name => $expectedWidth) {
            $dimensions = getimagesize($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $key, (string) $name, 'jpg'));

            $this->assertNotFalse($dimensions);
            $this->assertLessThanOrEqual((int) $expectedWidth, $dimensions[0], sprintf('Misura %s troppo grande.', $name));
        }
    }

    #[Test]
    public function il_webp_pesa_meno_del_jpeg(): void
    {
        $paths = self::app()->get(MediaPaths::class);
        $processor = self::app()->get(ImageProcessor::class);

        $key = $paths->generateKey();
        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $key, 'extension' => 'jpg'];

        $processor->process($this->fakeImage()->path(), MediaPaths::COLLECTION_GALLERY, $key, 'jpg');

        $webp = filesize($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $key, 'large', 'webp'));
        $jpeg = filesize($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $key, 'large', 'jpg'));

        $this->assertLessThan($jpeg, $webp, 'Il WebP dovrebbe pesare meno del JPEG a parita di contenuto.');
    }

    // -----------------------------------------------------------------------
    //  Filigrana
    // -----------------------------------------------------------------------

    #[Test]
    public function il_file_della_filigrana_e_presente(): void
    {
        $watermark = self::app()->get(WatermarkService::class);

        $this->assertNotNull(
            $watermark->watermarkFile(),
            'Manca resources/images/watermark.png: eseguire php scripts/generate-placeholders.php',
        );
    }

    #[Test]
    public function la_filigrana_modifica_l_immagine_pubblicata(): void
    {
        self::app()->get(\App\Services\SettingsService::class)->ensureDefaults();

        $paths = self::app()->get(MediaPaths::class);
        $processor = self::app()->get(ImageProcessor::class);
        $source = $this->fakeImage(1600, 1000);

        $senzaFiligrana = $paths->generateKey();
        $conFiligrana = $paths->generateKey();

        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $senzaFiligrana, 'extension' => 'jpg'];
        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $conFiligrana, 'extension' => 'jpg'];

        $processor->process($source->path(), MediaPaths::COLLECTION_GALLERY, $senzaFiligrana, 'jpg', applyWatermark: false);
        $processor->process($source->path(), MediaPaths::COLLECTION_GALLERY, $conFiligrana, 'jpg', applyWatermark: true);

        $pulita = file_get_contents($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $senzaFiligrana, 'large', 'jpg'));
        $marchiata = file_get_contents($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $conFiligrana, 'large', 'jpg'));

        // Stessa immagine di partenza: se i byte differiscono, la filigrana
        // e stata davvero applicata.
        $this->assertNotSame($pulita, $marchiata, 'La filigrana non ha modificato l immagine.');
    }

    #[Test]
    public function la_miniatura_non_riceve_la_filigrana(): void
    {
        self::app()->get(\App\Services\SettingsService::class)->ensureDefaults();

        $paths = self::app()->get(MediaPaths::class);
        $processor = self::app()->get(ImageProcessor::class);
        $source = $this->fakeImage(1600, 1000);

        $senza = $paths->generateKey();
        $con = $paths->generateKey();

        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $senza, 'extension' => 'jpg'];
        $this->storedKeys[] = ['collection' => MediaPaths::COLLECTION_GALLERY, 'key' => $con, 'extension' => 'jpg'];

        $processor->process($source->path(), MediaPaths::COLLECTION_GALLERY, $senza, 'jpg', applyWatermark: false);
        $processor->process($source->path(), MediaPaths::COLLECTION_GALLERY, $con, 'jpg', applyWatermark: true);

        // Su una miniatura da 400 pixel la filigrana coprirebbe il soggetto:
        // per questo viene applicata solo alle misure maggiori.
        $this->assertSame(
            file_get_contents($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $senza, 'thumb', 'jpg')),
            file_get_contents($paths->publicPath(MediaPaths::COLLECTION_GALLERY, $con, 'thumb', 'jpg')),
        );
    }

    // -----------------------------------------------------------------------
    //  Flusso completo
    // -----------------------------------------------------------------------

    #[Test]
    public function il_caricamento_in_album_salva_le_foto_e_aggiorna_i_contatori(): void
    {
        self::app()->get(\App\Services\SettingsService::class)->ensureDefaults();

        $albums = self::app()->get(AlbumRepository::class);
        $albumId = $albums->create([
            'title' => 'Album di prova',
            'status' => Album::STATUS_PUBLISHED,
        ]);

        $user = $this->createUser();

        $report = self::app()->get(PhotoService::class)->uploadToAlbum(
            $albumId,
            [$this->fakeImage(), $this->fakeImage(1000, 1400)],
            $user,
        );

        $this->assertSame(2, $report->uploadedCount, implode(' ', $report->errors));
        $this->assertFalse($report->hasErrors());

        $photos = self::app()->get(PhotoRepository::class)->allForAlbum($albumId);
        $this->assertCount(2, $photos);

        foreach ($photos as $photo) {
            $this->storedKeys[] = [
                'collection' => MediaPaths::COLLECTION_GALLERY,
                'key' => $photo->storageKey,
                'extension' => $photo->extension,
            ];

            // Ogni fotografia deve avere un testo alternativo, anche generato.
            $this->assertNotSame('', $photo->alt());
        }

        // Il contatore denormalizzato e la copertina si aggiornano da soli.
        $album = $albums->find($albumId);
        $this->assertSame(2, $album?->photosCount);
        $this->assertNotNull($album?->coverPhotoId);
    }

    #[Test]
    public function i_file_rifiutati_non_bloccano_quelli_validi(): void
    {
        self::app()->get(\App\Services\SettingsService::class)->ensureDefaults();

        $albumId = self::app()->get(AlbumRepository::class)->create([
            'title' => 'Album misto',
            'status' => Album::STATUS_PUBLISHED,
        ]);

        $report = self::app()->get(PhotoService::class)->uploadToAlbum(
            $albumId,
            [$this->fakeImage(), $this->fakeDisguisedFile(), $this->fakeImage()],
            $this->createUser(),
        );

        // Con trenta foto alla volta, far ricominciare tutto per un solo file
        // corrotto sarebbe inaccettabile: i validi passano comunque.
        $this->assertSame(2, $report->uploadedCount);
        $this->assertCount(1, $report->errors);

        foreach (self::app()->get(PhotoRepository::class)->allForAlbum($albumId) as $photo) {
            $this->storedKeys[] = [
                'collection' => MediaPaths::COLLECTION_GALLERY,
                'key' => $photo->storageKey,
                'extension' => $photo->extension,
            ];
        }
    }

    #[Test]
    public function eliminare_una_foto_rimuove_anche_i_file(): void
    {
        self::app()->get(\App\Services\SettingsService::class)->ensureDefaults();

        $albumId = self::app()->get(AlbumRepository::class)->create([
            'title' => 'Album da svuotare',
            'status' => Album::STATUS_PUBLISHED,
        ]);

        $user = $this->createUser();
        self::app()->get(PhotoService::class)->uploadToAlbum($albumId, [$this->fakeImage()], $user);

        $photos = self::app()->get(PhotoRepository::class)->allForAlbum($albumId);
        $photo = $photos[0];

        $paths = self::app()->get(MediaPaths::class);
        $publicFile = $paths->publicPath(MediaPaths::COLLECTION_GALLERY, $photo->storageKey, 'large', 'webp');
        $originalFile = $paths->originalPath(MediaPaths::COLLECTION_GALLERY, $photo->storageKey, $photo->extension);

        $this->assertFileExists($publicFile);
        $this->assertFileExists($originalFile);

        self::app()->get(PhotoService::class)->delete($photo->id, $user);

        // Niente file orfani su disco: su hosting condiviso lo spazio conta.
        $this->assertFileDoesNotExist($publicFile);
        $this->assertFileDoesNotExist($originalFile);
        $this->assertCount(0, self::app()->get(PhotoRepository::class)->allForAlbum($albumId));
    }
}
