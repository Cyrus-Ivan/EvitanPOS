import { LucideIcon } from 'lucide-react';

export type Permission = 'access-reports' | 'access-audit-logs' | 'manage-users' | 'access-settings' | 'access-backup' | 'view-item-cost';

export interface Auth {
    user: User;
    permissions: Record<Permission, boolean>;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    // Minimum role required to see this item. Omit for Cashier-level (everyone).
    minRole?: UserRole;
}

export type ToastType = 'success' | 'info' | 'warning' | 'error';

export interface FlashToast {
    type: ToastType;
    message: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { toast?: FlashToast | null };
    [key: string]: unknown;
}

export type UserRole = 'owner' | 'admin' | 'cashier';

export interface Category {
    id: string;
    name: string;
    archived_at: string | null;
    items_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Variant {
    id: string;
    item_id: string;
    name: string;
    sku: string | null;
    barcode: string | null;
    selling_price: string;
    // Present only for the Owner — stripped server-side for everyone else.
    cost?: string | null;
    low_stock_threshold: number;
    archived_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Item {
    id: string;
    name: string;
    unit: string;
    category_id: string | null;
    description: string | null;
    archived_at: string | null;
    category?: Pick<Category, 'id' | 'name'> | null;
    variants?: Variant[];
    created_at: string;
    updated_at: string;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
}

export interface User {
    id: string;
    name: string | null;
    username: string;
    role: UserRole;
    avatar?: string;
    // Present on managed-user listings (Phase 13); set when the user is archived.
    deleted_at?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

// A user row as surfaced by the User Management listing (Phase 13).
export interface ManagedUser {
    id: string;
    name: string | null;
    username: string;
    role: UserRole;
    deleted_at: string | null;
    created_at: string;
}
