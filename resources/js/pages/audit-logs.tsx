import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit Logs', href: '/audit-logs' }];

export default function AuditLogs() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={ScrollText} title="Audit Logs" description="Immutable record of significant business actions. Arrives in Phase 15." />
            </div>
        </AppLayout>
    );
}
