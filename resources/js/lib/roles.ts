import { type UserRole } from '@/types';

// Single client-side mirror of App\Enums\UserRole::level() — the one place the
// Owner > Admin > Cashier hierarchy is encoded on the frontend. Keep in sync
// with the backend enum; everything else imports from here so there is no
// duplicated hierarchy to drift.
export const ROLE_LEVEL: Record<UserRole, number> = {
    owner: 3,
    admin: 2,
    cashier: 1,
};

/** Whether `role` clears a minimum-role requirement (mirrors UserRole::atLeast). */
export function roleAtLeast(role: UserRole, minRole: UserRole): boolean {
    return ROLE_LEVEL[role] >= ROLE_LEVEL[minRole];
}

/** Whether `actor` strictly outranks `target` (mirrors UserPolicy::outranks). */
export function outranks(actor: UserRole, target: UserRole): boolean {
    return ROLE_LEVEL[actor] > ROLE_LEVEL[target];
}
