import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales', href: '/sales' }];

export default function Sales() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={ReceiptText} title="Sales" description="Completed transactions, returns, and defects. Arrives in Phase 9." />
            </div>
        </AppLayout>
    );
}
