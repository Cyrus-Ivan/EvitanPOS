<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Cashier = 'cashier';

    /**
     * Rank within the Owner > Admin > Cashier hierarchy. Higher outranks lower.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Cashier => 1,
        };
    }

    /**
     * Whether this role satisfies a minimum-role requirement (so the Owner
     * implicitly clears any Admin or Cashier gate).
     */
    public function atLeast(UserRole $role): bool
    {
        return $this->level() >= $role->level();
    }
}
