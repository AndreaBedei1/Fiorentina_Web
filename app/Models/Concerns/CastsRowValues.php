<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use DateTimeImmutable;

/**
 * Conversioni dalle righe grezze di PDO ai tipi PHP dei modelli.
 *
 * MySQL restituisce comunque stringhe per date e decimali, e con
 * `strict_variables` attivo in Twig un valore del tipo sbagliato si trasforma
 * in un errore di rendering: convertire una volta sola all'ingresso evita
 * controlli sparsi in tutto il progetto.
 */
trait CastsRowValues
{
    /** @param array<string, mixed> $row */
    protected static function castString(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, mixed> $row */
    protected static function castNullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $row */
    protected static function castInt(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<string, mixed> $row */
    protected static function castNullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $row */
    protected static function castFloat(array $row, string $key, float $default = 0.0): float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<string, mixed> $row */
    protected static function castNullableFloat(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $row */
    protected static function castBool(array $row, string $key, bool $default = false): bool
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return (bool) (is_numeric($value) ? (int) $value : $value);
    }

    /** @param array<string, mixed> $row */
    protected static function castDate(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<array-key, mixed>
     */
    protected static function castJson(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
