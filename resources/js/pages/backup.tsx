import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { DatabaseBackup } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Database Backup', href: '/backup' }];

export default function Backup() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Database Backup" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={DatabaseBackup} title="Database Backup" description="Back up and restore the SQLite database. Arrives in Phase 16." />
            </div>
        </AppLayout>
    );
}
