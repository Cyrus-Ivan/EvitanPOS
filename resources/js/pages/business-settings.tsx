import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Store } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business Settings', href: '/business-settings' }];

export default function BusinessSettings() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business Settings" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={Store} title="Business Settings" description="Business name, address, TIN, and receipt footer. Arrives in Phase 4." />
            </div>
        </AppLayout>
    );
}
