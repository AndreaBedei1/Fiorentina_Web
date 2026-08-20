<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/**
 * Contenuto social memorizzato in locale.
 *
 * La sincronizzazione non cancella mai i post esistenti: se Instagram o
 * Facebook non rispondono, la homepage continua a mostrare l'ultimo stato utile
 * invece di una sezione vuota.
 */
final class SocialPost
{
    use CastsRowValues;

    public const PROVIDER_INSTAGRAM = 'instagram';
    public const PROVIDER_FACEBOOK = 'facebook';
    public const PROVIDER_YOUTUBE = 'youtube';

    public function __construct(
        public readonly int $id,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $permalink,
        public readonly string $mediaType,
        public readonly ?string $mediaUrl,
        public readonly ?string $thumbnailUrl,
        public readonly ?string $localThumbKey,
        public readonly ?string $caption,
        public readonly ?string $author,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly bool $isVisible,
        public readonly ?DateTimeImmutable $syncedAt,
        /** Vero se la voce e stata inserita a mano dal pannello. */
        public readonly bool $isManual = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            provider: self::castString($row, 'provider', self::PROVIDER_INSTAGRAM),
            externalId: self::castString($row, 'external_id'),
            permalink: self::castString($row, 'permalink'),
            mediaType: self::castString($row, 'media_type', 'image'),
            mediaUrl: self::castNullableString($row, 'media_url'),
            thumbnailUrl: self::castNullableString($row, 'thumbnail_url'),
            localThumbKey: self::castNullableString($row, 'local_thumb_key'),
            caption: self::castNullableString($row, 'caption'),
            author: self::castNullableString($row, 'author'),
            publishedAt: self::castDate($row, 'published_at'),
            isVisible: self::castBool($row, 'is_visible', true),
            syncedAt: self::castDate($row, 'synced_at'),
            isManual: self::castBool($row, 'is_manual'),
        );
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_FACEBOOK => 'Facebook',
            self::PROVIDER_YOUTUBE => 'YouTube',
            default => 'Instagram',
        };
    }

    public function isVideo(): bool
    {
        return $this->mediaType === 'video' || $this->provider === self::PROVIDER_YOUTUBE;
    }

    public function shortCaption(int $limit = 120): string
    {
        return $this->caption === null ? '' : Str::truncate($this->caption, $limit);
    }

    /** Testo alternativo dell'anteprima, mai vuoto. */
    public function alt(): string
    {
        $caption = $this->shortCaption(90);

        return $caption !== ''
            ? $this->providerLabel() . ': ' . $caption
            : 'Contenuto ' . $this->providerLabel() . ' di Baraonda Fiorentina';
    }
}
