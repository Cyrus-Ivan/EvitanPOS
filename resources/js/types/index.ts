import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
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

export interface User {
    id: string;
    name: string;
    username: string;
    role: UserRole;
    avatar?: string;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
