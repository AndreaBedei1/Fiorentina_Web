<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Exceptions\HttpException;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Protezione CSRF.
 *
 * Il middleware e globale, non applicato rotta per rotta: e l unico modo per
 * garantire che nessun form aggiunto in futuro resti scoperto per distrazione.
 */
final class CsrfTest extends IntegrationTestCase
{
    private Csrf $csrf;

    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
        $this->startSession();
        $this->resetSession();

        $this->csrf = self::app()->get(Csrf::class);
        $this->middleware = self::app()->make(CsrfMiddleware::class);
    }

    private function passThrough(Request $request): Response
    {
        return $this->middleware->handle($request, static fn (): Response => Response::text('passato'));
    }

    #[Test]
    public function il_token_e_lungo_e_casuale(): void
    {
        $token = $this->csrf->token();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    #[Test]
    public function il_token_resta_stabile_nella_stessa_sessione(): void
    {
        // Un token diverso a ogni richiesta romperebbe la navigazione con piu
        // schede aperte, cosa che gli amministratori fanno di continuo.
        $this->assertSame($this->csrf->token(), $this->csrf->token());
    }

    #[Test]
    public function la_rigenerazione_produce_un_token_diverso(): void
    {
        $primo = $this->csrf->token();
        $secondo = $this->csrf->regenerate();

        $this->assertNotSame($primo, $secondo);
        $this->assertFalse($this->csrf->verify($primo));
        $this->assertTrue($this->csrf->verify($secondo));
    }

    #[Test]
    public function le_richieste_di_lettura_non_richiedono_il_token(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $response = $this->passThrough(Request::create($method, '/notizie'));

            $this->assertSame(200, $response->status());
        }
    }

    #[Test]
    public function una_post_senza_token_viene_respinta(): void
    {
        $this->expectException(HttpException::class);

        $this->passThrough(Request::create('POST', '/contatti', ['name' => 'Marco']));
    }

    #[Test]
    public function una_post_con_token_sbagliato_viene_respinta(): void
    {
        $this->csrf->token();

        $this->expectException(HttpException::class);

        $this->passThrough(Request::create('POST', '/contatti', [Csrf::FIELD => str_repeat('a', 64)]));
    }

    #[Test]
    public function una_post_con_token_valido_passa(): void
    {
        $token = $this->csrf->token();

        $response = $this->passThrough(Request::create('POST', '/contatti', [Csrf::FIELD => $token]));

        $this->assertSame(200, $response->status());
    }

    #[Test]
    public function il_token_puo_arrivare_dall_intestazione(): void
    {
        $token = $this->csrf->token();

        $request = Request::create('POST', '/admin/galleria/1/carica', [], [], [
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);

        $this->assertSame(200, $this->passThrough($request)->status());
    }

    #[Test]
    public function la_richiesta_respinta_produce_uno_stato_419(): void
    {
        try {
            $this->passThrough(Request::create('POST', '/contatti'));
            $this->fail('La richiesta senza token doveva essere respinta.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
        }
    }
}
