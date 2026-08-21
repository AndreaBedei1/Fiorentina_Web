<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Articolo del catalogo merchandising. */
final class Product
{
    use CastsRowValues;

    /**
     * @param list<ProductImage>   $images
     * @param list<ProductVariant> $variants
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly ?string $categorySlug,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $shortDescription,
        public readonly ?string $description,
        public readonly float $price,
        public readonly string $optionName,
        public readonly bool $isFeatured,
        public readonly int $sortOrder,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly array $images,
        public readonly array $variants,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed>  $row
     * @param list<ProductImage>    $images
     * @param list<ProductVariant>  $variants
     */
    public static function fromRow(array $row, array $images = [], array $variants = []): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            categoryId: self::castNullableInt($row, 'category_id'),
            categoryName: self::castNullableString($row, 'category_name'),
            categorySlug: self::castNullableString($row, 'category_slug'),
            name: self::castString($row, 'name'),
            slug: self::castString($row, 'slug'),
            shortDescription: self::castNullableString($row, 'short_description'),
            description: self::castNullableString($row, 'description'),
            price: self::castFloat($row, 'price'),
            optionName: self::castString($row, 'option_name', 'Taglia'),
            isFeatured: self::castBool($row, 'is_featured'),
            sortOrder: self::castInt($row, 'sort_order'),
            metaTitle: self::castNullableString($row, 'meta_title'),
            metaDescription: self::castNullableString($row, 'meta_description'),
            images: $images,
            variants: $variants,
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function hasVariants(): bool
    {
        return $this->variants !== [];
    }

    public function primaryImage(): ?ProductImage
    {
        foreach ($this->images as $image) {
            if ($image->isPrimary) {
                return $image;
            }
        }

        return $this->images[0] ?? null;
    }

    public function summary(int $limit = 140): string
    {
        if ($this->shortDescription !== null && trim($this->shortDescription) !== '') {
            return Str::truncate($this->shortDescription, $limit);
        }

        return Str::excerpt($this->description ?? '', $limit);
    }

    public function seoTitle(): string
    {
        return $this->metaTitle ?? $this->name;
    }

    public function seoDescription(): string
    {
        return $this->metaDescription ?? $this->summary(160);
    }
}
