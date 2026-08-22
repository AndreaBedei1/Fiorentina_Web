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

    /** @param array<string, mixed> $author */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $excerpt,
        /** HTML già sanificato in scrittura: nei template va stampato con |raw. */
        public readonly ?string $content,
        public readonly ?string $imageKey,
        public readonly ?int $authorId,
        public readonly DateTimeImmutable $publishedAt,
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
            excerpt: self::castNullableString($row, 'excerpt'),
            content: self::castNullableString($row, 'content'),
            imageKey: self::castNullableString($row, 'image_key'),
            authorId: self::castNullableInt($row, 'author_id'),
            publishedAt: self::castDate($row, 'published_at') ?? new DateTimeImmutable(),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    /** Riassunto per card e meta description, con fallback sul contenuto. */
    public function summary(int $limit = 180): string
    {
        if ($this->excerpt !== null && trim($this->excerpt) !== '') {
            return Str::truncate($this->excerpt, $limit);
        }

        return Str::excerpt($this->content ?? '', $limit);
    }

    /**
     * Il pezzo di indirizzo che identifica la notizia.
     *
     * Il numero e cio che conta: il titolo lo segue soltanto per rendere il
     * collegamento leggibile quando finisce in una chat o su un social. Se il
     * titolo cambia, cambia anche la coda, ma il numero resta e il vecchio
     * indirizzo continua a portare qui.
     */
    public function urlKey(): string
    {
        $coda = Str::slug($this->title);

        return $coda === '' ? (string) $this->id : $this->id . '-' . $coda;
    }

    /**
     * Come si annuncia la fotografia a chi non la vede.
     *
     * Non si chiede a nessuno di scriverlo: il titolo dell'articolo e la cosa
     * che meglio descrive la sua fotografia fra quelle che il sito conosce
     * gia con certezza.
     */
    public function imageAlt(): string
    {
        return $this->title;
    }

    public function seoTitle(): string
    {
        return $this->title;
    }

    public function seoDescription(): string
    {
        return $this->summary(160);
    }
}
