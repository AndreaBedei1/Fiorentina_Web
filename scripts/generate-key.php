<?php

declare(strict_types=1);

/**
 * Genera la chiave applicativa APP_KEY.
 *
 *     php scripts/generate-key.php            mostra la chiave
 *     php scripts/generate-key.php --write    la scrive direttamente nel file .env
 *
 * La chiave firma sessioni e impronte del browser: cambiarla scollega tutti gli
 * amministratori. Va generata una volta e poi lasciata stare.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$key = 'base64:' . base64_encode(random_bytes(32));
$write = in_array('--write', array_slice($argv, 1), true);

if (! $write) {
    echo "Chiave generata:\n\n";
    echo "  APP_KEY=" . $key . "\n\n";
    echo "Copiala nel file .env, oppure rilancia con --write per scriverla in automatico.\n";
    exit(0);
}

$envPath = dirname(__DIR__) . '/.env';

if (! is_file($envPath)) {
    fwrite(STDERR, "File .env non trovato. Copia prima .env.example in .env.\n");
    exit(1);
}

$contents = (string) file_get_contents($envPath);

if (preg_match('/^APP_KEY=(.*)$/m', $contents, $matches) === 1) {
    if (trim($matches[1]) !== '') {
        fwrite(STDERR, "APP_KEY e gia impostata. Sovrascriverla scollegherebbe tutti gli amministratori.\n");
        fwrite(STDERR, "Se vuoi davvero cambiarla, modifica il file .env a mano.\n");
        exit(1);
    }

    $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents) ?? $contents;
} else {
    $contents .= "\nAPP_KEY=" . $key . "\n";
}

file_put_contents($envPath, $contents);

echo "APP_KEY scritta nel file .env.\n";
