<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Unico punto d'ingresso HTTP dell'applicazione: ogni richiesta passa da qui,
 * riscritta da Apache tramite mod_rewrite (si veda public/.htaccess).
 *
 * Questa cartella e l'unica che deve essere esposta sul web. Codice,
 * configurazione, originali delle fotografie e dipendenze stanno un livello
 * piu in alto e non sono raggiungibili da nessun indirizzo.
 */

use App\Core\Application;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    exit('<!doctype html><html lang="it"><meta charset="utf-8"><title>Installazione incompleta</title>'
        . '<body style="font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
        . '<h1>Installazione incompleta</h1>'
        . '<p>Le dipendenze non sono state installate. Esegui <code>composer install</code> '
        . 'nella cartella del progetto, oppure carica la cartella <code>vendor/</code> sul server.</p>'
        . '</body></html>');
}

require $autoload;

(new Application(dirname(__DIR__)))->boot()->run();
