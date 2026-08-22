<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;

/**
 * Persona del direttivo mostrata nell'organigramma di "Chi siamo".
 *
 * Quattro cose: nome, cognome, ruolo e - se c'e - una fotografia. Il ruolo e
 * una scritta e basta: prima era una riga di una tabella da scegliere in una
 * tendina, con accanto un campo di testo libero per quando la tendina non
 * bastava, e vinceva sempre il testo libero.
 */
final class OrganizationMember
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $role,
        public readonly ?string $photoKey,
        public readonly ?string $photoExtension,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            firstName: self::castString($row, 'first_name'),
            lastName: self::castString($row, 'last_name'),
            role: self::castNullableString($row, 'role'),
            photoKey: self::castNullableString($row, 'photo_key'),
            photoExtension: self::castNullableString($row, 'photo_extension'),
        );
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /** Iniziali per l'avatar segnaposto quando manca la fotografia. */
    public function initials(): string
    {
        return Str::initials($this->fullName());
    }

    public function hasPhoto(): bool
    {
        return $this->photoKey !== null && $this->photoKey !== '';
    }
}
