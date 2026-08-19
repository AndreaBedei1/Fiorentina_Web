<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Notizia pubblicata dagli amministratori. */
final class News
{
    use CastsRowValues;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    /** @param array<string, mixed> $author */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        /** HTML gia sanificato in scrittura: nei template va stampato con |raw. */
        public readonly ?string $content,
        public readonly ?string $imageKey,
        public readonly ?string $imageAlt,
        public readonly ?int $authorId,
        public readonly ?string $authorName,
        public readonly string $status,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly bool $isFeatured,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly int $views,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            title: self::castString($row, 'title'),
            slug: self::castString($row, 'slug'),
            excerpt: self::castNullableString($row, 'excerpt'),
            content: self::castNullableString($row, 'content'),
            imageKey: self::castNullableString($row, 'image_key'),
            imageAlt: self::castNullableString($row, 'image_alt'),
            authorId: self::castNullableInt($row, 'author_id'),
            authorName: self::castNullableString($row, 'author_name'),
            status: self::castString($row, 'status', self::STATUS_DRAFT),
            publishedAt: self::castDate($row, 'published_at'),
            isFeatured: self::castBool($row, 'is_featured'),
            metaTitle: self::castNullableString($row, 'meta_title'),
            metaDescription: self::castNullableString($row, 'meta_description'),
            views: self::castInt($row, 'views'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->publishedAt !== null
            && $this->publishedAt <= new DateTimeImmutable();
    }

    /** Notizia programmata: pubblicata ma con data futura. */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->publishedAt !== null
            && $this->publishedAt > new DateTimeImmutable();
    }

    public function statusLabel(): string
    {
        if ($this->isScheduled()) {
            return 'Programmata';
        }

        return match ($this->status) {
            self::STATUS_PUBLISHED => 'Pubblicata',
            self::STATUS_ARCHIVED => 'Archiviata',
            default => 'Bozza',
        };
    }

    /** Riassunto per card e meta description, con fallback sul contenuto. */
    public function summary(int $limit = 180): string
    {
        if ($this->excerpt !== null && trim($this->excerpt) !== '') {
            return Str::truncate($this->excerpt, $limit);
        }

        return Str::excerpt($this->content ?? '', $limit);
    }

    public function seoTitle(): string
    {
        return $this->metaTitle ?? $this->title;
    }

    public function seoDescription(): string
    {
        return $this->metaDescription ?? $this->summary(160);
    }
}
