<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Esito di un'operazione amministrativa, con messaggio gia pronto per l'utente.
 *
 * I service non conoscono la sessione ne i flash message: restituiscono questo
 * oggetto e sono i controller a decidere come mostrarlo.
 */
final readonly class AdminActionResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public bool $successful,
        public string $message,
        public array $data = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function success(string $message, array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /** @param array<string, mixed> $data */
    public static function failure(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
