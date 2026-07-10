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

    /**
     * The roles an actor of this role may assign to (and therefore manage):
     * strictly-lower roles only. So the Owner may assign Admin/Cashier, an Admin
     * may assign Cashier, and nobody may assign the Owner. Used to populate the
     * create/role UIs and to validate submitted roles.
     *
     * @return list<UserRole>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (UserRole $role): bool => $role->level() < $this->level(),
        ));
    }
}
