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

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const AVAILABILITY_IN_STOCK = 'in_stock';
    public const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';
    public const AVAILABILITY_PREORDER = 'preorder';
    public const AVAILABILITY_DISCONTINUED = 'discontinued';

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
        public readonly ?float $compareAtPrice,
        public readonly string $availability,
        public readonly bool $trackQuantity,
        public readonly ?int $quantity,
        public readonly bool $isFeatured,
        public readonly string $status,
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
            compareAtPrice: self::castNullableFloat($row, 'compare_at_price'),
            availability: self::castString($row, 'availability', self::AVAILABILITY_IN_STOCK),
            trackQuantity: self::castBool($row, 'track_quantity'),
            quantity: self::castNullableInt($row, 'quantity'),
            isFeatured: self::castBool($row, 'is_featured'),
            status: self::castString($row, 'status', self::STATUS_DRAFT),
            sortOrder: self::castInt($row, 'sort_order'),
            metaTitle: self::castNullableString($row, 'meta_title'),
            metaDescription: self::castNullableString($row, 'meta_description'),
            images: $images,
            variants: $variants,
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** Ordinabile: pubblicato, disponibile e, se tracciato, con giacenza. */
    public function isOrderable(): bool
    {
        if (! $this->isPublished()) {
            return false;
        }

        if (in_array($this->availability, [self::AVAILABILITY_OUT_OF_STOCK, self::AVAILABILITY_DISCONTINUED], true)) {
            return false;
        }

        if ($this->trackQuantity && ($this->quantity ?? 0) <= 0) {
            // Restano ordinabili le varianti con giacenza propria.
            return $this->availableVariants() !== [];
        }

        return true;
    }

    /** @return list<ProductVariant> */
    public function availableVariants(): array
    {
        return array_values(array_filter($this->variants, static fn (ProductVariant $v) => ! $v->isSoldOut()));
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

    public function availabilityLabel(): string
    {
        if ($this->trackQuantity && ($this->quantity ?? 0) <= 0 && $this->availableVariants() === []) {
            return 'Esaurito';
        }

        return match ($this->availability) {
            self::AVAILABILITY_OUT_OF_STOCK => 'Esaurito',
            self::AVAILABILITY_PREORDER => 'Su prenotazione',
            self::AVAILABILITY_DISCONTINUED => 'Non più disponibile',
            default => 'Disponibile',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PUBLISHED => 'Pubblicato',
            self::STATUS_ARCHIVED => 'Archiviato',
            default => 'Bozza',
        };
    }

    public function hasDiscount(): bool
    {
        return $this->compareAtPrice !== null && $this->compareAtPrice > $this->price;
    }

    public function discountPercentage(): int
    {
        if (! $this->hasDiscount() || $this->compareAtPrice === null || $this->compareAtPrice <= 0.0) {
            return 0;
        }

        return (int) round((1 - $this->price / $this->compareAtPrice) * 100);
    }

    /** Prezzo minimo fra le varianti: usato nelle card ("da 15,00 euro"). */
    public function priceFrom(): float
    {
        $prices = array_map(fn (ProductVariant $v): float => $v->priceFor($this->price), $this->availableVariants());

        return $prices === [] ? $this->price : min($prices);
    }

    public function hasPriceRange(): bool
    {
        $prices = array_map(fn (ProductVariant $v): float => $v->priceFor($this->price), $this->availableVariants());

        return count(array_unique($prices)) > 1;
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
