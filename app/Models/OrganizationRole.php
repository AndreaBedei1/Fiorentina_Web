<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/** Ruolo del direttivo (presidente, vicepresidente, responsabile contabile...). */
final class OrganizationRole
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly int $sortOrder,
        public readonly int $membersCount = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            name: self::castString($row, 'name'),
            slug: self::castString($row, 'slug'),
            description: self::castNullableString($row, 'description'),
            sortOrder: self::castInt($row, 'sort_order'),
            membersCount: self::castInt($row, 'members_count'),
        );
    }
}
