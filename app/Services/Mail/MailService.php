<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Application;
use App\Core\Config;
use App\Core\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Invio delle email transazionali.
 *
 * Tre modalita, scelte da `MAIL_MAILER`:
 *  - `log`  scrive i messaggi in storage/logs/mail (default in sviluppo);
 *  - `smtp` invia davvero, tipicamente via SMTP Aruba;
 *  - `null` scarta tutto, utile durante i test.
 *
 * Un invio fallito non deve mai far fallire l'azione dell'utente: se la mail di
 * conferma non parte, l'ordine e comunque registrato e visibile nel pannello.
 */
final class MailService
{
    private ?MailerInterface $mailer = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly ViewRenderer $view,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Invia una email renderizzando un template Twig.
     *
     * @param string|list<string>  $to
     * @param array<string, mixed> $data
     * @return bool false se l'invio non e riuscito (l'errore resta nei log).
     */
    public function send(
        string|array $to,
        string $subject,
        string $template,
        array $data = [],
        ?string $replyTo = null,
    ): bool {
        $recipients = array_values(array_filter(
            array_map('trim', (array) $to),
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));

        if ($recipients === []) {
            $this->logger->warning('Email non inviata: nessun destinatario valido.', ['subject' => $subject]);

            return false;
        }

        try {
            $html = $this->view->render($template, $data + [
                'subject' => $subject,
                'site_url' => $this->config->string('app.url'),
            ]);

            $email = (new Email())
                ->from(new Address(
                    $this->config->string('mail.from.address'),
                    $this->config->string('mail.from.name'),
                ))
                ->subject($subject)
                ->html($html)
                ->text($this->htmlToText($html));

            foreach ($recipients as $recipient) {
                $email->addTo($recipient);
            }

            if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
                $email->replyTo($replyTo);
            }

            $this->mailer()->send($email);

            $this->logger->info('Email inviata.', [
                'subject' => $subject,
                'to' => $recipients,
                'transport' => $this->config->string('mail.mailer'),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Invio email non riuscito.', [
                'subject' => $subject,
                'to' => $recipients,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function mailer(): MailerInterface
    {
        if ($this->mailer instanceof MailerInterface) {
            return $this->mailer;
        }

        $transport = match ($this->config->string('mail.mailer', 'log')) {
            'smtp' => Transport::fromDsn($this->smtpDsn()),
            'null' => new NullTransport(),
            default => new FileLogTransport(
                $this->app->storagePath($this->config->string('mail.log_directory', 'logs/mail')),
            ),
        };

        return $this->mailer = new Mailer($transport);
    }

    /** DSN SMTP costruito dalle variabili di ambiente. */
    private function smtpDsn(): string
    {
        $encryption = $this->config->string('mail.smtp.encryption', 'ssl');
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($this->config->string('mail.smtp.username')),
            rawurlencode($this->config->string('mail.smtp.password')),
            $this->config->string('mail.smtp.host'),
            $this->config->int('mail.smtp.port', 465),
        );

        if ($encryption === 'tls') {
            $dsn .= '?require_tls=1';
        }

        return $dsn;
    }

    /**
     * Versione testuale del messaggio.
     *
     * Serve sia ai client che non mostrano HTML sia ai filtri antispam, che
     * penalizzano le email con la sola parte HTML.
     */
    private function htmlToText(string $html): string
    {
        // Via per prime le parti che non sono contenuto.
        $text = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Le celle di tabella diventano separatori orizzontali, le righe a capo.
        $text = preg_replace('#</t[dh]>#i', ' | ', $text) ?? $text;
        $text = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/table)[^>]*>#i', "\n", $text) ?? $text;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        /*
         * Il layout a tabelle delle email lascia moltissime righe fatte di soli
         * spazi: senza questa normalizzazione la versione testuale risulta
         * illeggibile, che e proprio il contrario di cio che serve a chi legge
         * la posta senza HTML.
         */
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
            $line = trim($line, '| ');

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }
}
