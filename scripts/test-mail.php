<?php

declare(strict_types=1);

/**
 * Invia un messaggio di prova per verificare la configurazione della posta.
 *
 *     php scripts/test-mail.php                    manda ai destinatari configurati
 *     php scripts/test-mail.php mario@example.it   manda all'indirizzo indicato
 *
 * Esiste perche provare la posta passando dal modulo contatti significa
 * riempire un form, generare un messaggio finto nel pannello e non sapere,
 * quando non arriva niente, se il problema sia l'SMTP o il sito. Qui la
 * risposta e in una riga.
 *
 * Con MAIL_MAILER=log il messaggio non parte davvero: finisce in un file .eml
 * dentro storage/logs/mail/, che si apre con qualsiasi programma di posta.
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Config;
use App\Services\Mail\MailService;
use App\Services\SettingsService;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$config = $app->get(Config::class);
$settings = $app->get(SettingsService::class);

$modalita = $config->string('mail.mailer', 'log');

$destinatari = array_values(array_filter(array_slice($argv, 1), static function (string $arg): bool {
    return ! str_starts_with($arg, '-');
}));

if ($destinatari === []) {
    $destinatari = array_values(array_unique(array_filter([
        $settings->string('contact_email', $config->string('mail.to.contact')),
        $settings->string('contact_merchandising_email', $config->string('mail.to.orders')),
    ])));
}

Console::title('Prova di invio della posta');

Console::bullet(sprintf('Modalita:    %s', $modalita));

if ($modalita === 'smtp') {
    Console::bullet(sprintf(
        'Server:      %s:%d (%s)',
        $config->string('mail.host'),
        $config->int('mail.port'),
        $config->string('mail.encryption') ?: 'nessuna cifratura',
    ));
    Console::bullet(sprintf('Utente:      %s', $config->string('mail.username') ?: '(non impostato)'));
}

Console::bullet(sprintf(
    'Mittente:    %s <%s>',
    $config->string('mail.from.name'),
    $config->string('mail.from.address'),
));
Console::bullet(sprintf('Destinatari: %s', implode(', ', $destinatari)));
Console::line();

if ($destinatari === []) {
    Console::error('Nessun destinatario: indicane uno come argomento, oppure compilalo nel pannello alla voce Impostazioni.');
    exit(1);
}

if ($modalita === 'smtp' && $config->string('mail.username') === '') {
    Console::warn('MAIL_USERNAME e vuoto: con la maggior parte dei server SMTP l\'invio verra rifiutato.');
    Console::line();
}

$inviate = 0;

foreach ($destinatari as $destinatario) {
    $esito = $app->get(MailService::class)->send(
        $destinatario,
        'Prova di invio dal sito ' . $settings->string('site_group_name', 'Baraonda Fiorentina'),
        'emails/test.twig',
        [
            'mailer' => $modalita,
            'inviato_il' => date('d/m/Y \a\l\l\e H:i'),
            'destinatario' => $destinatario,
        ],
    );

    if ($esito) {
        Console::success(sprintf('Consegnato al server: %s', $destinatario));
        $inviate++;
    } else {
        Console::error(sprintf('Invio non riuscito: %s', $destinatario));
    }
}

Console::line();

if ($inviate === 0) {
    Console::error('Nessun messaggio inviato. Il motivo esatto e in storage/logs/app-' . date('Y-m-d') . '.log.');
    exit(1);
}

if ($modalita === 'log') {
    Console::warn('Modalita "log": i messaggi NON sono partiti davvero.');
    Console::bullet('Li trovi come file .eml in storage/logs/mail/');
    Console::bullet('Per inviarli sul serio: MAIL_MAILER=smtp nel file .env.');
    exit(0);
}

Console::success(sprintf('%d messaggi consegnati al server SMTP.', $inviate));
Console::bullet('Se non arrivano, controlla anche la posta indesiderata.');
Console::line();
Console::warn('Consegnato al server non significa recapitato: il rifiuto puo arrivare dopo, e in quel caso ricevi un avviso dal tuo provider.');
exit(0);
