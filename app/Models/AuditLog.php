<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Voce del registro delle azioni amministrative. */
final class AuditLog
{
    use CastsRowValues;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly ?string $userEmail,
        public readonly ?string $userRole,
        public readonly string $action,
        public readonly ?string $entityType,
        public readonly ?int $entityId,
        public readonly ?string $description,
        public readonly array $metadata,
        public readonly ?string $ip,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            userId: self::castNullableInt($row, 'user_id'),
            userEmail: self::castNullableString($row, 'user_email'),
            userRole: self::castNullableString($row, 'user_role'),
            action: self::castString($row, 'action'),
            entityType: self::castNullableString($row, 'entity_type'),
            entityId: self::castNullableInt($row, 'entity_id'),
            description: self::castNullableString($row, 'description'),
            metadata: self::castJson($row, 'metadata'),
            ip: self::castNullableString($row, 'ip'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
        );
    }

    public function actorLabel(): string
    {
        return $this->userEmail ?? 'sistema';
    }

    /** Azioni che meritano evidenza visiva nel registro. */
    public function isSensitive(): bool
    {
        return str_contains($this->action, 'DELETE')
            || str_contains($this->action, 'BLOCK')
            || str_contains($this->action, 'ROLE')
            || str_contains($this->action, 'FAILED');
    }
}
