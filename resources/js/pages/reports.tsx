import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ChartColumn } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];

export default function Reports() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={ChartColumn} title="Reports" description="Daily and monthly sales reporting. Arrives in Phase 12." />
            </div>
        </AppLayout>
    );
}
