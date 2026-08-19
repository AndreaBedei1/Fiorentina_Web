<?php

declare(strict_types=1);

namespace App\Core\Support;

use Psr\Log\LoggerInterface;

/**
 * Client HTTP essenziale basato su cURL.
 *
 * Perche non Guzzle: le uniche chiamate uscenti del progetto sono poche GET
 * verso API note, eseguite dal cron. cURL e garantito su Aruba, e evitare una
 * dipendenza con il suo albero di sotto-dipendenze mantiene leggero il pacchetto
 * da caricare via FTP.
 *
 * Una chiamata che fallisce non solleva eccezioni: restituisce null e lascia
 * traccia nei log, perche l'indisponibilita di un servizio esterno non deve
 * mai far fallire una sincronizzazione notturna.
 */
final class HttpClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 10,
        private readonly string $userAgent = 'BaraondaFiorentina/1.0 (+https://baraondafiorentina.it)',
    ) {
    }

    /**
     * Esegue una GET e restituisce il corpo della risposta.
     *
     * @param list<string> $headers
     */
    public function get(string $url, array $headers = []): ?string
    {
        if (! function_exists('curl_init')) {
            $this->logger->error('Estensione cURL non disponibile: chiamata esterna impossibile.');

            return null;
        }

        if (! $this->isAllowedUrl($url)) {
            $this->logger->error('URL esterno rifiutato.', ['url' => $url]);

            return null;
        }

        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headers,
            // Verifica del certificato sempre attiva: disattivarla renderebbe
            // le chiamate intercettabili.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        curl_close($handle);

        if ($body === false || $error !== '') {
            $this->logger->warning('Chiamata HTTP non riuscita.', ['url' => $url, 'error' => $error]);

            return null;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->warning('Chiamata HTTP con esito negativo.', [
                'url' => $url,
                'status' => $statusCode,
                'body' => mb_substr((string) $body, 0, 300),
            ]);

            return null;
        }

        return (string) $body;
    }

    /**
     * GET con decodifica JSON.
     *
     * @param list<string> $headers
     * @return array<string, mixed>|null
     */
    public function getJson(string $url, array $headers = []): ?array
    {
        $body = $this->get($url, $headers);

        if ($body === null) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Risposta JSON non valida.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Scarica un file su disco (miniature dei contenuti social).
     *
     * @param int $maxBytes Limite di sicurezza: evita che una risposta anomala
     *                      riempia lo spazio disco dell'hosting.
     */
    public function download(string $url, string $destination, int $maxBytes = 5 * 1024 * 1024): bool
    {
        $body = $this->get($url);

        if ($body === null || $body === '' || strlen($body) > $maxBytes) {
            return false;
        }

        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return false;
        }

        return file_put_contents($destination, $body) !== false;
    }

    /**
     * Consente solo http/https verso host pubblici.
     *
     * Le URL possono arrivare da risposte di terze parti (per esempio la
     * miniatura di un post): senza questo filtro una risposta manipolata
     * potrebbe far interrogare al server indirizzi della rete interna.
     */
    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        // Se l'host e gia un indirizzo IP, deve essere pubblico.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return true;
    }
}
