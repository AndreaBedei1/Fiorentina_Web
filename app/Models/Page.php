<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Pagina editoriale modificabile dagli amministratori. */
final class Page
{
    use CastsRowValues;

    public const SLUG_CHI_SIAMO = 'chi-siamo';
    public const SLUG_DIVENTA_SOCIO = 'diventa-socio';
    public const SLUG_CONTATTI = 'contatti';
    public const SLUG_PRIVACY = 'privacy';
    public const SLUG_COOKIE = 'cookie-policy';

    /** @param list<PageBlock> $blocks */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly ?string $intro,
        public readonly ?string $content,
        public readonly ?string $heroImageKey,
        public readonly ?string $heroImageAlt,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly string $status,
        public readonly bool $isSystem,
        public readonly array $blocks,
        public readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<PageBlock>      $blocks
     */
    public static function fromRow(array $row, array $blocks = []): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            slug: self::castString($row, 'slug'),
            title: self::castString($row, 'title'),
            subtitle: self::castNullableString($row, 'subtitle'),
            intro: self::castNullableString($row, 'intro'),
            content: self::castNullableString($row, 'content'),
            heroImageKey: self::castNullableString($row, 'hero_image_key'),
            heroImageAlt: self::castNullableString($row, 'hero_image_alt'),
            metaTitle: self::castNullableString($row, 'meta_title'),
            metaDescription: self::castNullableString($row, 'meta_description'),
            status: self::castString($row, 'status', 'published'),
            isSystem: self::castBool($row, 'is_system'),
            blocks: $blocks,
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** @return list<PageBlock> */
    public function visibleBlocks(): array
    {
        return array_values(array_filter($this->blocks, static fn (PageBlock $b) => $b->isVisible));
    }

    /** @return list<PageBlock> */
    public function blocksOfType(string $type): array
    {
        return array_values(array_filter(
            $this->visibleBlocks(),
            static fn (PageBlock $b) => $b->type === $type,
        ));
    }

    public function seoTitle(): string
    {
        return $this->metaTitle ?? $this->title;
    }

    public function seoDescription(): string
    {
        return $this->metaDescription ?? ($this->subtitle ?? '');
    }
}
