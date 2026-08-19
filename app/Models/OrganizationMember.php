<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;

/** Persona del direttivo mostrata nell'organigramma di "Chi siamo". */
final class OrganizationMember
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly ?int $roleId,
        public readonly ?string $roleName,
        public readonly string $fullName,
        public readonly ?string $roleTitle,
        public readonly ?string $bio,
        public readonly ?string $photoKey,
        public readonly ?string $photoExtension,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?int $memberSince,
        public readonly int $sortOrder,
        public readonly bool $isVisible,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            roleId: self::castNullableInt($row, 'role_id'),
            roleName: self::castNullableString($row, 'role_name'),
            fullName: self::castString($row, 'full_name'),
            roleTitle: self::castNullableString($row, 'role_title'),
            bio: self::castNullableString($row, 'bio'),
            photoKey: self::castNullableString($row, 'photo_key'),
            photoExtension: self::castNullableString($row, 'photo_extension'),
            email: self::castNullableString($row, 'email'),
            phone: self::castNullableString($row, 'phone'),
            memberSince: self::castNullableInt($row, 'member_since'),
            sortOrder: self::castInt($row, 'sort_order'),
            isVisible: self::castBool($row, 'is_visible', true),
        );
    }

    /** Titolo mostrato: quello specifico se presente, altrimenti il ruolo. */
    public function displayRole(): string
    {
        return $this->roleTitle ?? $this->roleName ?? '';
    }

    /** Iniziali per l'avatar segnaposto quando manca la fotografia. */
    public function initials(): string
    {
        return Str::initials($this->fullName);
    }

    public function hasPhoto(): bool
    {
        return $this->photoKey !== null && $this->photoKey !== '';
    }
}
