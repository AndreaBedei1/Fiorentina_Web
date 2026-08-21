<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/**
 * Amministratore del sito.
 *
 * Non esistono utenti pubblici: chi visita il sito non ha e non può avere un
 * account. Questa entita rappresenta esclusivamente lo staff del gruppo.
 */
final class User
{
    use CastsRowValues;

    public const ROLE_SUPER_ADMIN = 'SUPER_ADMIN';
    public const ROLE_ADMIN = 'ADMIN';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        /** Mai esposto ai template: serve solo ad AuthService per la verifica. */
        public readonly ?string $passwordHash,
        public readonly string $role,
        public readonly string $status,
        public readonly ?string $phone,
        public readonly ?DateTimeImmutable $passwordChangedAt,
        public readonly ?DateTimeImmutable $sessionsValidAfter,
        public readonly ?int $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $deletedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            name: self::castString($row, 'name'),
            email: self::castString($row, 'email'),
            passwordHash: self::castNullableString($row, 'password_hash'),
            role: self::castString($row, 'role', self::ROLE_ADMIN),
            status: self::castString($row, 'status', self::STATUS_PENDING),
            phone: self::castNullableString($row, 'phone'),
            passwordChangedAt: self::castDate($row, 'password_changed_at'),
            sessionsValidAfter: self::castDate($row, 'sessions_valid_after'),
            createdBy: self::castNullableInt($row, 'created_by'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
            deletedAt: self::castDate($row, 'deleted_at'),
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->deletedAt === null;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Un account senza password non ha ancora accettato l'invito. */
    public function canLogin(): bool
    {
        return $this->isActive() && $this->passwordHash !== null && $this->passwordHash !== '';
    }

    public function roleLabel(): string
    {
        return $this->isSuperAdmin() ? 'Super amministratore' : 'Amministratore';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Attivo',
            self::STATUS_BLOCKED => 'Bloccato',
            self::STATUS_PENDING => 'Invito in attesa',
            default => $this->status,
        };
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0] ?? $this->name;
    }

    /**
     * Rappresentazione sicura per template e log: senza hash della password.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
        ];
    }
}
