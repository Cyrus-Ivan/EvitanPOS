import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category, Item, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FolderCog, Package, Pencil } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventory' }];

const ALL_CATEGORIES = 'all';

interface Filters {
    search: string | null;
    category: string | null;
    status: string;
}

function priceLabel(item: Item): string {
    const prices = (item.variants ?? []).map((v) => Number(v.selling_price)).filter((n) => !Number.isNaN(n));
    if (prices.length === 0) {
        return '—';
    }
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    return min === max ? min.toFixed(2) : `${min.toFixed(2)} – ${max.toFixed(2)}`;
}

export default function InventoryIndex({
    items,
    categories,
    filters,
}: {
    items: Paginated<Item>;
    categories: Pick<Category, 'id' | 'name'>[];
    filters: Filters;
}) {
    const { hasRoleAtLeast } = usePermissions();
    const confirm = useConfirm();
    const canManage = hasRoleAtLeast('admin');

    const [search, setSearch] = useState(filters.search ?? '');
    const isFirstRender = useRef(true);

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { search, category: filters.category, status: filters.status, ...next };
        router.get(
            route('inventory'),
            {
                search: merged.search || undefined,
                category: merged.category && merged.category !== ALL_CATEGORIES ? merged.category : undefined,
                status: merged.status && merged.status !== 'active' ? merged.status : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['items', 'filters'] },
        );
    };

    // Debounce the search box so we don't fire a request per keystroke.
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        const timeout = setTimeout(() => applyFilters({ search }), 300);
        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const archiveItem = async (item: Item) => {
        if (
            await confirm({
                title: `Archive "${item.name}"?`,
                description: 'It will be hidden from the catalog but kept in history. You can restore it later.',
                destructive: true,
                confirmLabel: 'Archive',
            })
        ) {
            router.patch(route('items.archive', item.id), {}, { preserveScroll: true });
        }
    };

    const restoreItem = async (item: Item) => {
        if (await confirm({ title: `Restore "${item.name}"?`, description: 'It will reappear in the active catalog.', confirmLabel: 'Restore' })) {
            router.patch(route('items.restore', item.id), {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, SKU, barcode" className="w-64" />
                        <Select value={filters.category ?? ALL_CATEGORIES} onValueChange={(value) => applyFilters({ category: value })}>
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder="All categories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_CATEGORIES}>All categories</SelectItem>
                                {categories.map((category) => (
                                    <SelectItem key={category.id} value={category.id}>
                                        {category.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.status} onValueChange={(value) => applyFilters({ status: value })}>
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="archived">Archived</SelectItem>
                                <SelectItem value="all">All</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {canManage && (
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href={route('categories.index')}>
                                    <FolderCog className="size-4" /> Manage categories
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={route('items.create')}>New item</Link>
                            </Button>
                        </div>
                    )}
                </div>

                {items.data.length === 0 ? (
                    <EmptyState
                        icon={Package}
                        title="No items found"
                        description={
                            filters.search || filters.category
                                ? 'Try adjusting your search or filters.'
                                : 'Create your first item to start building the catalog.'
                        }
                        action={
                            canManage ? (
                                <Button asChild>
                                    <Link href={route('items.create')}>New item</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Unit</TableHead>
                                    <TableHead>Variants</TableHead>
                                    <TableHead>Price</TableHead>
                                    <TableHead>Status</TableHead>
                                    {canManage && <TableHead className="text-right">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.data.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">{item.name}</TableCell>
                                        <TableCell>{item.category?.name ?? '—'}</TableCell>
                                        <TableCell>{item.unit}</TableCell>
                                        <TableCell>{item.variants?.length ?? 0}</TableCell>
                                        <TableCell>{priceLabel(item)}</TableCell>
                                        <TableCell>
                                            {item.archived_at ? <Badge variant="secondary">Archived</Badge> : <Badge>Active</Badge>}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={route('items.edit', item.id)}>
                                                            <Pencil className="size-4" /> Edit
                                                        </Link>
                                                    </Button>
                                                    {item.archived_at ? (
                                                        <Button variant="ghost" size="sm" onClick={() => restoreItem(item)}>
                                                            Restore
                                                        </Button>
                                                    ) : (
                                                        <Button variant="ghost" size="sm" onClick={() => archiveItem(item)}>
                                                            Archive
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={items.links} />
            </div>
        </AppLayout>
    );
}
