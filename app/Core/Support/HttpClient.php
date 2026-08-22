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
 * traccia nei log, perché l'indisponibilita di un servizio esterno non deve
 * mai far fallire una sincronizzazione notturna.
 *
 * La classe non e final di proposito: e il confine con la rete, ed e l'unico
 * punto che i test hanno bisogno di sostituire con una risposta finta. Senza
 * questa possibilita, la lettura dei dati di un fornitore esterno resterebbe
 * verificabile solo con una chiamata vera.
 */
class HttpClient
{
    /**
     * Motivo per cui l'ultima chiamata non e riuscita.
     *
     * Serve a chi sta sopra: un fornitore che restituisce null non sa dire se
     * la chiave e sbagliata, se il limite di richieste e finito o se la rete
     * non risponde, e senza quella distinzione il pannello puo solo scrivere
     * "non ha funzionato". Vale per l'ultima chiamata e basta, che in PHP -
     * una richiesta, un processo - e esattamente quello che serve.
     */
    private ?string $ultimoErrore = null;

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
            $this->ultimoErrore = 'il server non puo effettuare chiamate esterne (cURL non disponibile)';

            return null;
        }

        if (! $this->isAllowedUrl($url)) {
            $this->logger->error('URL esterno rifiutato.', ['url' => $url]);
            $this->ultimoErrore = 'indirizzo esterno non consentito';

            return null;
        }

        $this->ultimoErrore = null;

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
        $codiceErrore = curl_errno($handle);

        curl_close($handle);

        if ($body === false || $error !== '') {
            $this->logger->warning('Chiamata HTTP non riuscita.', [
                'url' => $url,
                'errno' => $codiceErrore,
                'error' => $error,
            ]);
            $this->ultimoErrore = $this->spiegaGuasto($codiceErrore, $error);

            return null;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->warning('Chiamata HTTP con esito negativo.', [
                'url' => $url,
                'status' => $statusCode,
                'body' => mb_substr((string) $body, 0, 300),
            ]);
            $this->ultimoErrore = $this->spiega($statusCode, (string) $body);

            return null;
        }

        return (string) $body;
    }

    /** Perche l'ultima chiamata non e riuscita, oppure null se e andata bene. */
    public function lastFailure(): ?string
    {
        return $this->ultimoErrore;
    }

    /**
     * Dichiara riuscita l'ultima chiamata.
     *
     * Serve alle sottoclassi che rispondono senza uscire in rete - i test, in
     * pratica - perche altrimenti il motivo della chiamata precedente
     * resterebbe appeso e sembrerebbe riferito a questa.
     */
    protected function azzeraErrore(): void
    {
        $this->ultimoErrore = null;
    }

    /**
     * Traduce il guasto di cURL in una frase che dica cosa e successo.
     *
     * Prima erano tutti la stessa frase: "il server non ha risposto (rete
     * assente o tempo scaduto)". Ma un certificato che non si riesce a
     * verificare non e una rete assente, e chi legge quel messaggio va a
     * controllare la connessione invece del certificato - che e esattamente
     * quello che e successo la prima volta che il calendario non si e
     * sincronizzato.
     *
     * I codici sono quelli che si incontrano davvero: DNS che non risolve,
     * connessione rifiutata, tempo scaduto, certificato non verificabile.
     */
    private function spiegaGuasto(int $codice, string $messaggio): string
    {
        $frase = match ($codice) {
            CURLE_COULDNT_RESOLVE_HOST => 'il nome del server non si risolve (DNS assente o indirizzo sbagliato)',
            CURLE_COULDNT_CONNECT => 'la connessione al server e stata rifiutata',
            CURLE_OPERATION_TIMEDOUT => sprintf('il server non ha risposto entro %d secondi', $this->timeoutSeconds),
            CURLE_SSL_CACERT,
            CURLE_SSL_CACERT_BADFILE,
            CURLE_PEER_FAILED_VERIFICATION => 'il certificato del server non e verificabile: '
                . 'sul server manca l elenco delle autorita di certificazione (curl.cainfo nel php.ini)',
            CURLE_SSL_CONNECT_ERROR => 'la connessione cifrata non e riuscita',
            default => 'il server non ha risposto',
        };

        return $messaggio === '' ? $frase : $frase . ' - ' . $messaggio;
    }

    /**
     * Traduce l'esito negativo in una frase che dica cosa fare.
     *
     * I codici sono quelli che si incontrano davvero parlando con un'API a
     * chiave: 401 e 403 quando la chiave non va, 429 quando si e chiesto
     * troppo, 5xx quando il problema e dall'altra parte.
     *
     * Al codice si aggiunge, quando c'e, la spiegazione del fornitore stesso:
     * e in inglese, ma "Your API token is invalid" dice esattamente cosa
     * sistemare, mentre "errore 400" lascia a indovinare.
     */
    private function spiega(int $statusCode, string $corpo): string
    {
        $base = match (true) {
            $statusCode === 401, $statusCode === 403 => 'la chiave di accesso non e valida o non copre questi dati',
            $statusCode === 429 => 'limite di richieste raggiunto, il fornitore chiede di aspettare',
            $statusCode === 404 => 'l indirizzo richiesto non esiste piu',
            $statusCode >= 500 => 'il servizio del fornitore non e raggiungibile in questo momento',
            default => sprintf('il fornitore ha risposto con un errore (codice %d)', $statusCode),
        };

        $dettaglio = $this->messaggioDelFornitore($corpo);

        return $dettaglio === null ? $base : $base . ' - ' . $dettaglio;
    }

    /** Estrae il messaggio d'errore dal corpo JSON, se ce n'e uno leggibile. */
    private function messaggioDelFornitore(string $corpo): ?string
    {
        if (trim($corpo) === '') {
            return null;
        }

        try {
            $decoded = json_decode($corpo, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['message', 'error', 'detail'] as $chiave) {
            $valore = $decoded[$chiave] ?? null;

            if (is_string($valore) && trim($valore) !== '') {
                return mb_substr(trim(preg_replace('/\s+/u', ' ', $valore) ?? ''), 0, 160);
            }
        }

        return null;
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
            $this->ultimoErrore = 'la risposta del fornitore non era leggibile';

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

        // Se l'host è già un indirizzo IP, deve essere pubblico.
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
