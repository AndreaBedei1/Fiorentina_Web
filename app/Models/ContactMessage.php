<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Messaggio ricevuto dal modulo contatti. */
final class ContactMessage
{
    use CastsRowValues;

    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_SPAM = 'spam';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $subject,
        public readonly string $message,
        public readonly ?string $ip,
        public readonly string $status,
        public readonly ?DateTimeImmutable $readAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            name: self::castString($row, 'name'),
            email: self::castString($row, 'email'),
            subject: self::castString($row, 'subject'),
            message: self::castString($row, 'message'),
            ip: self::castNullableString($row, 'ip'),
            status: self::castString($row, 'status', self::STATUS_NEW),
            readAt: self::castDate($row, 'read_at'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
        );
    }

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_READ => 'Letto',
            self::STATUS_REPLIED => 'Risposto',
            self::STATUS_ARCHIVED => 'Archiviato',
            self::STATUS_SPAM => 'Spam',
            default => 'Nuovo',
        };
    }
}
