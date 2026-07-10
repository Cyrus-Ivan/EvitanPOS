import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Paginated, StockLedgerEntry } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock History', href: '/inventory/stock-history' },
];

const ALL = 'all';

// Human labels for the ledger movement kind. A sale line carries a sale_type
// (sale/return/defect); a manual recount is a stock adjustment.
const TYPE_LABEL: Record<string, string> = {
    sale: 'Sale',
    stock_adjustment: 'Adjustment',
};

const SALE_TYPE_LABEL: Record<string, string> = {
    sale: 'Sale',
    return: 'Return',
    defect: 'Defect',
};

function movementLabel(entry: StockLedgerEntry): string {
    if (entry.type === 'sale' && entry.sale_type) {
        return SALE_TYPE_LABEL[entry.sale_type] ?? entry.sale_type;
    }
    return TYPE_LABEL[entry.type] ?? entry.type;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

interface VariantOption {
    id: string;
    name: string | null;
    item: string | null;
}

// A "no variant" product has a null name; show the parent item name instead.
function variantLabel(variant: { name: string | null; item: string | null }): string {
    if (variant.name && variant.item) {
        return `${variant.item} — ${variant.name}`;
    }
    return variant.name ?? variant.item ?? '—';
}

interface Filters {
    variant: string | null;
    type: string | null;
    date_from: string | null;
    date_to: string | null;
}

export default function StockHistory({
    entries,
    variants,
    types,
    filters,
}: {
    entries: Paginated<StockLedgerEntry>;
    variants: VariantOption[];
    types: string[];
    filters: Filters;
}) {
    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            route('stock-history.index'),
            {
                variant: merged.variant && merged.variant !== ALL ? merged.variant : undefined,
                type: merged.type && merged.type !== ALL ? merged.type : undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['entries', 'filters'] },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock History" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select value={filters.variant ?? ALL} onValueChange={(value) => applyFilters({ variant: value })}>
                        <SelectTrigger className="w-64">
                            <SelectValue placeholder="All variants" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All variants</SelectItem>
                            {variants.map((variant) => (
                                <SelectItem key={variant.id} value={variant.id}>
                                    {variantLabel(variant)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.type ?? ALL} onValueChange={(value) => applyFilters({ type: value })}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            {types.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {TYPE_LABEL[type] ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">From</span>
                        <Input
                            type="date"
                            value={filters.date_from ?? ''}
                            max={filters.date_to ?? undefined}
                            onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                            className="w-40"
                        />
                        <span className="text-sm text-muted-foreground">To</span>
                        <Input
                            type="date"
                            value={filters.date_to ?? ''}
                            min={filters.date_from ?? undefined}
                            onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                            className="w-40"
                        />
                    </div>
                </div>

                {entries.data.length === 0 ? (
                    <EmptyState
                        icon={ScrollText}
                        title="No stock movements"
                        description="Ledger entries appear here as stock is adjusted, sold, returned or defected."
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Variant</TableHead>
                                    <TableHead>Movement</TableHead>
                                    <TableHead className="text-right">Change</TableHead>
                                    <TableHead className="text-right">On-hand</TableHead>
                                    <TableHead>By</TableHead>
                                    <TableHead>Notes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="tabular-nums">{formatWhen(entry.created_at)}</TableCell>
                                        <TableCell>
                                            {entry.variant ? (
                                                <>
                                                    <span className="font-medium">{entry.variant.item ?? '—'}</span>
                                                    {entry.variant.name && <span className="text-muted-foreground"> · {entry.variant.name}</span>}
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={entry.type === 'stock_adjustment' ? 'secondary' : 'outline'}>{movementLabel(entry)}</Badge>
                                        </TableCell>
                                        <TableCell
                                            className={`text-right tabular-nums ${
                                                entry.quantity > 0 ? 'text-emerald-600 dark:text-emerald-400' : entry.quantity < 0 ? 'text-destructive' : ''
                                            }`}
                                        >
                                            {entry.quantity > 0 ? `+${entry.quantity}` : entry.quantity}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{entry.resulting_snapshot}</TableCell>
                                        <TableCell>{entry.performed_by ?? '—'}</TableCell>
                                        <TableCell className="max-w-xs truncate text-muted-foreground" title={entry.notes ?? undefined}>
                                            {entry.notes ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={entries.links} />
            </div>
        </AppLayout>
    );
}
