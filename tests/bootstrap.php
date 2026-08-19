<?php

declare(strict_types=1);

/**
 * Bootstrap della suite di test.
 *
 * Carica l'autoloader e il file .env (per le credenziali del database), ma
 * lascia che phpunit.xml sovrascriva le variabili che contano: ambiente,
 * posta, fornitori esterni e costi di hashing.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

// Il database dei test e sempre quello dedicato: nessun test puo scrivere
// per errore su quello di sviluppo.
$_ENV['DB_DATABASE'] = $_ENV['DB_TEST_DATABASE'] ?? 'baraonda_test';
$_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'];

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');
