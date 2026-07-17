import { roleAtLeast } from '@/lib/roles';
import { type Permission, type SharedData, type UserRole } from '@/types';
import { usePage } from '@inertiajs/react';

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

    const hasRoleAtLeast = (minRole: UserRole): boolean => (role ? roleAtLeast(role, minRole) : false);

    return { role, can, hasRoleAtLeast };
}
