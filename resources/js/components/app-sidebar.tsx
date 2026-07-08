import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ChartColumn, DatabaseBackup, History, LayoutGrid, Package, ReceiptText, ScrollText, ShoppingCart, Store, UsersRound } from 'lucide-react';
import AppLogo from './app-logo';

// Full navigation surface. Role-based visibility (Cashier / Admin / Owner) is
// intentionally NOT enforced yet — that lands in Phase 3 (Authorization). The
// trailing comment on each item records its intended minimum role so the gating
// pass has a reference.
const mainNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid }, // Cashier
    { title: 'Point of Sale', url: '/pos', icon: ShoppingCart }, // Cashier
    { title: 'Sales', url: '/sales', icon: ReceiptText }, // Cashier
    { title: 'Inventory', url: '/inventory', icon: Package }, // Cashier (view)
    { title: 'Reports', url: '/reports', icon: ChartColumn }, // Admin
    { title: 'User Management', url: '/users', icon: UsersRound }, // Admin
    { title: 'Login History', url: '/login-history', icon: History }, // Cashier (own)
    { title: 'Audit Logs', url: '/audit-logs', icon: ScrollText }, // Admin
    { title: 'Business Settings', url: '/business-settings', icon: Store }, // Owner
    { title: 'Database Backup', url: '/backup', icon: DatabaseBackup }, // Owner
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
