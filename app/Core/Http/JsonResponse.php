<?php

declare(strict_types=1);

namespace App\Core\Http;

use JsonException;

/** Risposta JSON usata dagli endpoint interni dell'area amministrativa. */
final class JsonResponse extends Response
{
    /** @param mixed $data */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        try {
            $json = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            $json = '{"error":"Serializzazione della risposta non riuscita."}';
            $status = 500;
        }

        parent::__construct($json, $status, array_merge([
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers));
    }

    /** @param array<string, mixed> $extra */
    public static function ok(array $extra = []): self
    {
        return new self(['ok' => true] + $extra);
    }

    /** @param array<string, mixed> $extra */
    public static function error(string $message, int $status = 422, array $extra = []): self
    {
        return new self(['ok' => false, 'message' => $message] + $extra, $status);
    }
}
