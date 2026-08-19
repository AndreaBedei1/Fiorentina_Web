<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Fallimento della validazione lato server: porta con se gli errori per campo. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'I dati inviati non sono validi.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, string> Primo messaggio per ciascun campo. */
    public function firstErrors(): array
    {
        return array_map(static fn (array $messages): string => $messages[0] ?? '', $this->errors);
    }
}
