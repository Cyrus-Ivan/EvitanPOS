import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { UsersRound } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'User Management', href: '/users' }];

export default function Users() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={UsersRound} title="User Management" description="Create and manage employee accounts. Arrives in Phase 13." />
            </div>
        </AppLayout>
    );
}
