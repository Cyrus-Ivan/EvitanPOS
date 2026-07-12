import EmptyState from '@/components/empty-state';
import SalesBarChart from '@/components/reports/sales-bar-chart';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { formatPeso } from '@/lib/money';
import type { BreadcrumbItem, ReportPeriod, ReportRow, ReportTotals } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChartColumn } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];

interface Filters {
    period: ReportPeriod;
    date_from: string;
    date_to: string;
}

// Bucket → table row label. Daily bucket is 'YYYY-MM-DD', monthly is 'YYYY-MM'.
function formatBucket(bucket: string, period: ReportPeriod): string {
    const date = new Date(period === 'monthly' ? `${bucket}-01` : bucket);
    if (Number.isNaN(date.getTime())) return bucket;
    return period === 'monthly'
        ? date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
        : date.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
}

function StatCard({ label, value, tone }: { label: string; value: string; tone?: 'net' | 'negative' }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
                <div
                    className={
                        'mt-1 text-2xl font-semibold tabular-nums ' +
                        (tone === 'net' ? 'text-foreground' : tone === 'negative' ? 'text-muted-foreground' : 'text-foreground')
                    }
                >
                    {value}
                </div>
            </CardContent>
        </Card>
    );
}

export default function Reports({
    period,
    rows,
    totals,
    canViewCost,
    filters,
}: {
    period: ReportPeriod;
    rows: ReportRow[];
    totals: ReportTotals;
    canViewCost: boolean;
    filters: Filters;
}) {
    // Date changes keep the current granularity.
    const applyFilters = (next: Partial<Pick<Filters, 'date_from' | 'date_to'>>) => {
        const merged = { ...filters, ...next };
        router.get(
            route('reports'),
            { period, date_from: merged.date_from || undefined, date_to: merged.date_to || undefined },
            { preserveState: true, preserveScroll: true, replace: true, only: ['period', 'rows', 'totals', 'filters'] },
        );
    };

    // Switching granularity resets to that period's default range (this month for
    // daily, this year for monthly) — sending no dates lets the server default.
    const switchPeriod = (next: string) => {
        if (!next || next === period) return;
        router.get(
            route('reports'),
            { period: next },
            { preserveState: true, preserveScroll: true, replace: true, only: ['period', 'rows', 'totals', 'filters'] },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {/* Controls: granularity toggle + date range. */}
                <div className="flex flex-wrap items-center gap-3">
                    <ToggleGroup type="single" variant="outline" value={period} onValueChange={switchPeriod}>
                        <ToggleGroupItem value="daily" aria-label="Daily sales">
                            Daily
                        </ToggleGroupItem>
                        <ToggleGroupItem value="monthly" aria-label="Monthly sales">
                            Monthly
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">From</span>
                        <Input
                            type="date"
                            value={filters.date_from}
                            max={filters.date_to || undefined}
                            onChange={(e) => applyFilters({ date_from: e.target.value })}
                            className="w-40"
                        />
                        <span className="text-sm text-muted-foreground">To</span>
                        <Input
                            type="date"
                            value={filters.date_to}
                            min={filters.date_from || undefined}
                            onChange={(e) => applyFilters({ date_to: e.target.value })}
                            className="w-40"
                        />
                    </div>
                </div>

                {/* Summary cards — recomputed net = gross − returns − defects. */}
                <div className={'grid gap-3 sm:grid-cols-2 ' + (canViewCost ? 'lg:grid-cols-6' : 'lg:grid-cols-4')}>
                    <StatCard label="Gross sales" value={formatPeso(totals.gross_sales)} />
                    <StatCard label="Returns" value={formatPeso(totals.returns)} tone="negative" />
                    <StatCard label="Defects" value={formatPeso(totals.defects)} tone="negative" />
                    <StatCard label="Net sales" value={formatPeso(totals.net_sales)} tone="net" />
                    {canViewCost && <StatCard label="Gross profit" value={formatPeso(totals.profit ?? '0')} tone="net" />}
                    {canViewCost && (
                        <StatCard label="Uncosted sales" value={formatPeso(totals.uncosted_sales ?? '0')} tone="negative" />
                    )}
                </div>

                {rows.length === 0 ? (
                    <EmptyState
                        icon={ChartColumn}
                        title="No sales in this range"
                        description="Pick a different date range, or ring up a sale to see it reported here."
                    />
                ) : (
                    <>
                        {/* Net sales over time. */}
                        <Card>
                            <CardContent className="p-4">
                                <div className="mb-3 text-sm font-medium text-muted-foreground">
                                    Net sales by {period === 'monthly' ? 'month' : 'day'}
                                </div>
                                <SalesBarChart rows={rows} period={period} />
                            </CardContent>
                        </Card>

                        {/* Breakdown table. */}
                        <div className="rounded-xl border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{period === 'monthly' ? 'Month' : 'Day'}</TableHead>
                                        <TableHead className="text-right">Txns</TableHead>
                                        <TableHead className="text-right">Gross</TableHead>
                                        <TableHead className="text-right">Returns</TableHead>
                                        <TableHead className="text-right">Defects</TableHead>
                                        <TableHead className="text-right">Net</TableHead>
                                        {canViewCost && <TableHead className="text-right">Uncosted</TableHead>}
                                        {canViewCost && <TableHead className="text-right">Profit</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.bucket}>
                                            <TableCell className="font-medium">{formatBucket(row.bucket, period)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{row.transactions}</TableCell>
                                            <TableCell className="text-right tabular-nums">{formatPeso(row.gross_sales)}</TableCell>
                                            <TableCell className="text-right tabular-nums text-muted-foreground">
                                                {Number(row.returns) > 0 ? `−${formatPeso(row.returns)}` : formatPeso(row.returns)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums text-muted-foreground">
                                                {Number(row.defects) > 0 ? `−${formatPeso(row.defects)}` : formatPeso(row.defects)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">{formatPeso(row.net_sales)}</TableCell>
                                            {canViewCost && (
                                                <TableCell className="text-right tabular-nums text-muted-foreground">
                                                    {formatPeso(row.uncosted_sales ?? '0')}
                                                </TableCell>
                                            )}
                                            {canViewCost && (
                                                <TableCell className="text-right tabular-nums">{formatPeso(row.profit ?? '0')}</TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="font-medium">Total</TableCell>
                                        <TableCell className="text-right tabular-nums">{totals.transactions}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatPeso(totals.gross_sales)}</TableCell>
                                        <TableCell className="text-right tabular-nums text-muted-foreground">
                                            {Number(totals.returns) > 0 ? `−${formatPeso(totals.returns)}` : formatPeso(totals.returns)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums text-muted-foreground">
                                            {Number(totals.defects) > 0 ? `−${formatPeso(totals.defects)}` : formatPeso(totals.defects)}
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">{formatPeso(totals.net_sales)}</TableCell>
                                        {canViewCost && (
                                            <TableCell className="text-right font-semibold tabular-nums text-muted-foreground">
                                                {formatPeso(totals.uncosted_sales ?? '0')}
                                            </TableCell>
                                        )}
                                        {canViewCost && (
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatPeso(totals.profit ?? '0')}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
