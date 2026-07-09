<?php

namespace App\Enums;

enum FamilyRole: string
{
    case Admin = 'admin';
    case Guardian = 'guardian';
    case Member = 'member';
    case Child = 'child';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Guardian => 'Guardian',
            self::Member => 'Member',
            self::Child => 'Child',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full control over family settings and members',
            self::Guardian => 'Can manage devices and view all locations',
            self::Member => 'Standard family member with basic access',
            self::Child => 'Restricted access, location always shared',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'purple',
            self::Guardian => 'blue',
            self::Member => 'green',
            self::Child => 'orange',
        };
    }

    /**
     * Check if this role can manage other members.
     */
    public function canManageMembers(): bool
    {
        return in_array($this, [self::Admin, self::Guardian]);
    }

    /**
     * Check if this role can view all family devices.
     */
    public function canViewAllDevices(): bool
    {
        return in_array($this, [self::Admin, self::Guardian]);
    }

    /**
     * Check if this role can manage family settings.
     */
    public function canManageSettings(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Check if this role can invite new members.
     */
    public function canInviteMembers(): bool
    {
        return in_array($this, [self::Admin, self::Guardian]);
    }

    /**
     * Check if this role can remove members.
     */
    public function canRemoveMembers(): bool
    {
        return in_array($this, [self::Admin, self::Guardian]);
    }

    /**
     * Check if this role's location is always shared.
     */
    public function locationAlwaysShared(): bool
    {
        return $this === self::Child;
    }

    /**
     * Get hierarchy level (higher = more permissions).
     */
    public function hierarchyLevel(): int
    {
        return match ($this) {
            self::Admin => 4,
            self::Guardian => 3,
            self::Member => 2,
            self::Child => 1,
        };
    }

    /**
     * Check if this role outranks another role.
     */
    public function outranks(FamilyRole $other): bool
    {
        return $this->hierarchyLevel() > $other->hierarchyLevel();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
