import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/hooks/use-currency';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Paginated, PaymentType, TransactionListRow, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales', href: '/sales' }];

const ALL = 'all';

// Human labels + badge tone per payment type.
const PAYMENT: Record<PaymentType, { label: string; variant: 'default' | 'secondary' | 'outline' }> = {
    cash: { label: 'Cash', variant: 'default' },
    cashless: { label: 'Cashless', variant: 'secondary' },
    others: { label: 'Others', variant: 'outline' },
};

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function discountLabel(row: TransactionListRow, money: (value: number | string) => string): string {
    const amount = Number(row.discount);
    if (!row.discount_type || amount <= 0) {
        return '—';
    }
    return row.discount_type === 'percentage' ? `${amount}%` : money(row.discount);
}

interface Filters {
    search: string | null;
    payment_type: string | null;
    user: string | null;
    date_from: string | null;
    date_to: string | null;
}

export default function Sales({
    transactions,
    users,
    paymentTypes,
    filters,
}: {
    transactions: Paginated<TransactionListRow>;
    users: Pick<User, 'id' | 'name' | 'username'>[];
    paymentTypes: PaymentType[];
    filters: Filters;
}) {
    const { format: money } = useCurrency();

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            route('sales'),
            {
                search: merged.search || undefined,
                payment_type: merged.payment_type && merged.payment_type !== ALL ? merged.payment_type : undefined,
                user: merged.user && merged.user !== ALL ? merged.user : undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['transactions', 'filters'] },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        type="search"
                        placeholder="Search receipt no."
                        defaultValue={filters.search ?? ''}
                        onChange={(e) => applyFilters({ search: e.target.value || null })}
                        className="w-52"
                    />
                    <Select value={filters.payment_type ?? ALL} onValueChange={(value) => applyFilters({ payment_type: value })}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All payments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All payments</SelectItem>
                            {paymentTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {PAYMENT[type]?.label ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.user ?? ALL} onValueChange={(value) => applyFilters({ user: value })}>
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="All cashiers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All cashiers</SelectItem>
                            {users.map((user) => (
                                <SelectItem key={user.id} value={user.id}>
                                    {user.name ?? user.username}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground text-sm">From</span>
                        <Input
                            type="date"
                            value={filters.date_from ?? ''}
                            max={filters.date_to ?? undefined}
                            onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                            className="w-40"
                        />
                        <span className="text-muted-foreground text-sm">To</span>
                        <Input
                            type="date"
                            value={filters.date_to ?? ''}
                            min={filters.date_from ?? undefined}
                            onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                            className="w-40"
                        />
                    </div>
                </div>

                {transactions.data.length === 0 ? (
                    <EmptyState icon={ReceiptText} title="No sales" description="Completed transactions will appear here once you ring up a sale." />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Receipt No.</TableHead>
                                    <TableHead>When</TableHead>
                                    <TableHead>Cashier</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead className="text-right">Items</TableHead>
                                    <TableHead className="text-right">Discount</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transactions.data.map((row) => (
                                    // The row onClick is a redundant mouse affordance; the receipt-number
                                    // Link below is the keyboard-accessible path (a focusable row would
                                    // double every tab stop for keyboard users).
                                    <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(route('sales.show', row.id))}>
                                        <TableCell className="font-medium tabular-nums">
                                            <Link href={route('sales.show', row.id)} onClick={(e) => e.stopPropagation()} className="hover:underline">
                                                {row.transaction_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="tabular-nums">{formatWhen(row.created_at)}</TableCell>
                                        <TableCell>{row.user?.name ?? row.user?.username ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={PAYMENT[row.payment_type]?.variant ?? 'outline'}>
                                                {PAYMENT[row.payment_type]?.label ?? row.payment_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{row.items_count}</TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">{discountLabel(row, money)}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">{money(row.total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={transactions.links} />
            </div>
        </AppLayout>
    );
}
