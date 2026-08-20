<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/**
 * Blocco di contenuto di una pagina editoriale.
 *
 * Tipi fissi e limitati: gli amministratori compilano campi chiari invece di
 * comporre layout. E la differenza fra un CMS usabile e un page builder che
 * nessuno riesce a mantenere.
 */
final class PageBlock
{
    use CastsRowValues;

    public const TYPE_TEXT = 'text';
    public const TYPE_LIST = 'list';
    public const TYPE_STEPS = 'steps';
    public const TYPE_HIGHLIGHT = 'highlight';
    public const TYPE_CTA = 'cta';
    public const TYPE_FAQ = 'faq';
    public const TYPE_STATS = 'stats';
    public const TYPE_TIMELINE = 'timeline';

    /** @param list<array<string, string>> $items */
    public function __construct(
        public readonly int $id,
        public readonly int $pageId,
        public readonly string $type,
        public readonly ?string $title,
        public readonly ?string $subtitle,
        public readonly ?string $body,
        public readonly array $items,
        public readonly ?string $icon,
        public readonly ?string $linkUrl,
        public readonly ?string $linkLabel,
        public readonly int $sortOrder,
        public readonly bool $isVisible,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $items = [];

        foreach (self::castJson($row, 'items') as $item) {
            if (is_array($item)) {
                $items[] = array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $item);
            }
        }

        return new self(
            id: self::castInt($row, 'id'),
            pageId: self::castInt($row, 'page_id'),
            type: self::castString($row, 'type', self::TYPE_TEXT),
            title: self::castNullableString($row, 'title'),
            subtitle: self::castNullableString($row, 'subtitle'),
            body: self::castNullableString($row, 'body'),
            items: $items,
            icon: self::castNullableString($row, 'icon'),
            linkUrl: self::castNullableString($row, 'link_url'),
            linkLabel: self::castNullableString($row, 'link_label'),
            sortOrder: self::castInt($row, 'sort_order'),
            isVisible: self::castBool($row, 'is_visible', true),
        );
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_LIST => 'Elenco puntato',
            self::TYPE_STEPS => 'Passaggi numerati',
            self::TYPE_HIGHLIGHT => 'Riquadro in evidenza',
            self::TYPE_CTA => 'Invito all\'azione',
            self::TYPE_FAQ => 'Domande frequenti',
            self::TYPE_STATS => 'Numeri chiave',
            self::TYPE_TIMELINE => 'Linea del tempo',
            default => 'Testo',
        };
    }

    /** @return array<string, string> */
    public static function allTypes(): array
    {
        return [
            self::TYPE_TEXT => self::typeLabel(self::TYPE_TEXT),
            self::TYPE_LIST => self::typeLabel(self::TYPE_LIST),
            self::TYPE_STEPS => self::typeLabel(self::TYPE_STEPS),
            self::TYPE_HIGHLIGHT => self::typeLabel(self::TYPE_HIGHLIGHT),
            self::TYPE_CTA => self::typeLabel(self::TYPE_CTA),
            self::TYPE_FAQ => self::typeLabel(self::TYPE_FAQ),
            self::TYPE_STATS => self::typeLabel(self::TYPE_STATS),
            self::TYPE_TIMELINE => self::typeLabel(self::TYPE_TIMELINE),
        ];
    }
}
