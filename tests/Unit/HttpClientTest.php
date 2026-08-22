<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\HttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Che cosa racconta il client quando una chiamata non riesce.
 *
 * Il motivo di questi test: per giorni la sincronizzazione del calendario ha
 * detto "il server non ha risposto (rete assente o tempo scaduto)" mentre il
 * problema era tutt'altro - il certificato non si riusciva a verificare,
 * perche a quel PHP mancava l'elenco delle autorita di certificazione. Chi
 * legge "rete assente" va a controllare la connessione, che funziona, e si
 * ferma li.
 *
 * Un messaggio che indica la cosa sbagliata costa piu di nessun messaggio.
 */
final class HttpClientTest extends TestCase
{
    #[Test]
    public function un_nome_che_non_esiste_lo_dice(): void
    {
        $client = new HttpClient(new NullLogger(), 5);

        // Il dominio .invalid e riservato apposta: non risolvera mai.
        self::assertNull($client->get('https://baraonda.invalid/qualcosa'));
        self::assertStringContainsString('non si risolve', (string) $client->lastFailure());
    }

    /** Un indirizzo che non e http/https non esce nemmeno di casa. */
    #[Test]
    public function gli_indirizzi_non_consentiti_si_fermano_prima(): void
    {
        $client = new HttpClient(new NullLogger(), 5);

        self::assertNull($client->get('file:///etc/passwd'));
        self::assertSame('indirizzo esterno non consentito', $client->lastFailure());
    }

    /** Dopo una chiamata riuscita non resta appeso il motivo della precedente. */
    #[Test]
    public function il_motivo_vale_solo_per_l_ultima_chiamata(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            public function get(string $url, array $headers = []): ?string
            {
                if (str_contains($url, 'rotto')) {
                    return parent::get($url, $headers);
                }

                // Simula una risposta riuscita senza uscire in rete.
                $this->azzeraErrore();

                return '{"ok":true}';
            }
        };

        $client->get('https://baraonda.invalid/rotto');
        self::assertNotNull($client->lastFailure());

        $client->get('https://esempio.test/va-bene');
        self::assertNull($client->lastFailure());
    }
}
