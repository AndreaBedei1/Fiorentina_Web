<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\FootballMatch;
use DateTimeImmutable;

/**
 * Voce del calendario unificato.
 *
 * Struttura comune a partite e appuntamenti del gruppo, con un `type` esplicito
 * e un'icona simbolica. L'informazione non passa mai dal solo colore: ogni voce
 * ha un'etichetta testuale leggibile anche da chi non distingue le tinte o usa
 * uno screen reader.
 */
final readonly class CalendarEntry
{
    public const TYPE_MATCH = 'match';
    public const TYPE_EVENT = 'event';

    public function __construct(
        public string $type,
        public int $id,
        public string $title,
        public DateTimeImmutable $startsAt,
        public string $icon,
        public string $categoryLabel,
        public string $colorKey,
        public ?string $url = null,
        public ?string $location = null,
        public ?string $detail = null,
        public bool $isCancelled = false,
        public ?FootballMatch $match = null,
        public ?Event $event = null,
    ) {
    }

    /**
     * Vero se l'orario e davvero quello, falso se la lega ha fissato solo la
     * data. Gli appuntamenti del gruppo hanno sempre un orario scelto da una
     * persona, quindi per loro e sempre vero.
     */
    public function timeConfirmed(): bool
    {
        return $this->match?->kickoffTimeConfirmed ?? true;
    }

    public static function fromEvent(Event $event): self
    {
        return new self(
            type: self::TYPE_EVENT,
            id: $event->id,
            title: $event->title,
            startsAt: $event->startsAt,
            icon: $event->category?->icon ?? 'calendar',
            categoryLabel: $event->category?->name ?? 'Evento',
            colorKey: $event->category?->color ?? 'viola',
            url: '/eventi/' . $event->slug,
            location: $event->locationLine() !== '' ? $event->locationLine() : null,
            detail: $event->summary(90),
            isCancelled: $event->isCancelled(),
            event: $event,
        );
    }

    public static function fromMatch(FootballMatch $match): self
    {
        return new self(
            type: self::TYPE_MATCH,
            id: $match->id,
            title: $match->title(),
            startsAt: $match->kickoffAt,
            icon: 'ball',
            categoryLabel: $match->competition,
            colorKey: 'rosso',
            url: null,
            location: $match->venue,
            detail: $match->isHome ? 'In casa' : 'In trasferta',
            isCancelled: $match->status === FootballMatch::STATUS_CANCELLED,
            match: $match,
        );
    }

    public function isMatch(): bool
    {
        return $this->type === self::TYPE_MATCH;
    }

    public function isEvent(): bool
    {
        return $this->type === self::TYPE_EVENT;
    }

    /** Classi Tailwind del badge. Mappa esplicita: le classi dinamiche non vengono compilate. */
    public function badgeClasses(): string
    {
        return match ($this->colorKey) {
            'rosso' => 'bg-rosso-50 text-rosso-800 ring-rosso-200',
            'ambra' => 'bg-amber-50 text-amber-900 ring-amber-200',
            'verde' => 'bg-emerald-50 text-emerald-900 ring-emerald-200',
            'blu' => 'bg-sky-50 text-sky-900 ring-sky-200',
            'sabbia' => 'bg-sabbia-100 text-sabbia-800 ring-sabbia-300',
            default => 'bg-viola-50 text-viola-800 ring-viola-200',
        };
    }

    public function accentClasses(): string
    {
        return match ($this->colorKey) {
            'rosso' => 'border-l-rosso-600',
            'ambra' => 'border-l-amber-500',
            'verde' => 'border-l-emerald-600',
            'blu' => 'border-l-sky-600',
            'sabbia' => 'border-l-sabbia-500',
            default => 'border-l-viola-600',
        };
    }
}
