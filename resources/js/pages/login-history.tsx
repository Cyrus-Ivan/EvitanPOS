import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { History } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Login History', href: '/login-history' }];

export default function LoginHistory() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Login History" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={History} title="Login History" description="Authentication events: logins, logouts, and crash detection. Arrives in Phase 14." />
            </div>
        </AppLayout>
    );
}
