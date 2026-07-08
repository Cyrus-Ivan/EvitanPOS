import EmptyState from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Point of Sale', href: '/pos' }];

export default function Pos() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Point of Sale" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <EmptyState icon={ShoppingCart} title="Point of Sale" description="The checkout screen where cashiers ring up sales. Arrives in Phase 8." />
            </div>
        </AppLayout>
    );
}
