import ItemForm from '@/components/inventory/item-form';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'New item', href: '/inventory/create' },
];

export default function CreateItem({ categories }: { categories: Pick<Category, 'id' | 'name'>[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New item" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <ItemForm categories={categories} />
            </div>
        </AppLayout>
    );
}
