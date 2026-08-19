<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Trasporto di sviluppo: invece di spedire, scrive il messaggio su file.
 *
 * Ogni email diventa un file `.eml` apribile con qualsiasi client di posta,
 * allegati e HTML inclusi. Permette di provare l'intero flusso ordini senza
 * configurare un SMTP reale e senza rischiare invii accidentali a clienti veri.
 */
final class FileLogTransport extends AbstractTransport
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o755, true) && ! is_dir($this->directory)) {
            return;
        }

        $recipients = array_map(
            static fn ($address): string => $address->getAddress(),
            $message->getEnvelope()->getRecipients(),
        );

        $filename = sprintf(
            '%s-%s-%s.eml',
            date('Ymd-His'),
            substr(preg_replace('/[^a-z0-9]+/i', '-', $recipients[0] ?? 'nessuno') ?? 'nessuno', 0, 40),
            substr(bin2hex(random_bytes(3)), 0, 6),
        );

        file_put_contents(
            rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $filename,
            $message->toString(),
        );
    }

    public function __toString(): string
    {
        return 'file-log://' . $this->directory;
    }
}
