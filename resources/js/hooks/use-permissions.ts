import { type Permission, type SharedData, type UserRole } from '@/types';
import { usePage } from '@inertiajs/react';

// Client mirror of App\Enums\UserRole::level() — keep in sync with the backend.
const ROLE_LEVEL: Record<UserRole, number> = {
    owner: 3,
    admin: 2,
    cashier: 1,
};

/**
 * Single place the UI asks "is this allowed". `can()` reads the resolved
 * permission map shared from the server (HandleInertiaRequests), while
 * `hasRoleAtLeast()` mirrors the Owner > Admin > Cashier hierarchy for
 * role-based nav gating.
 */
export function usePermissions() {
    const { auth } = usePage<SharedData>().props;
    const role = auth.user?.role;

    const can = (permission: Permission): boolean => auth.permissions?.[permission] ?? false;

    const hasRoleAtLeast = (minRole: UserRole): boolean => {
        if (!role) {
            return false;
        }
        return ROLE_LEVEL[role] >= ROLE_LEVEL[minRole];
    };

    return { role, can, hasRoleAtLeast };
}
