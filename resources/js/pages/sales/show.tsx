import ReturnDialog from '@/components/sales/return-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, PaymentType, SaleReturnEvent, SaleReturnType, TransactionDetail } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Printer, RotateCcw, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

const PAYMENT_LABEL: Record<PaymentType, string> = {
    cash: 'Cash',
    online: 'Online',
    others: 'Others',
};

const RETURN_TYPE_LABEL: Record<SaleReturnType, string> = {
    return: 'Return',
    defect: 'Defect',
};

const RETURN_TYPE_BADGE: Record<SaleReturnType, 'secondary' | 'destructive' | 'outline'> = {
    return: 'secondary',
    defect: 'outline',
};

function peso(value: string | null): string {
    if (value === null) {
        return '—';
    }
    const number = Number(value);
    return Number.isNaN(number) ? value : `₱${number.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

// Sum of line totals before the transaction-level discount.
function subtotalOf(transaction: TransactionDetail): number {
    return transaction.items.reduce((sum, line) => sum + Number(line.total), 0);
}

function discountLabel(transaction: TransactionDetail): string {
    const amount = Number(transaction.discount);
    if (!transaction.discount_type || amount <= 0) {
        return '—';
    }
    return transaction.discount_type === 'percentage' ? `${amount}%` : peso(transaction.discount);
}

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground uppercase">{label}</span>
            <span className="text-sm tabular-nums">{value}</span>
        </div>
    );
}

export default function SalesShow({
    transaction,
    canViewCost,
}: {
    transaction: TransactionDetail;
    canViewCost: boolean;
}) {
    const { hasRoleAtLeast } = usePermissions();
    const [dialogType, setDialogType] = useState<SaleReturnType | null>(null);
    const [printing, setPrinting] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales' },
        { title: transaction.transaction_number, href: `/sales/${transaction.id}` },
    ];

    // Any line with quantity still available to return/defect.
    const hasReturnable = transaction.items.some((line) => line.quantity - line.returned_quantity > 0);
    const canManageInventory = hasRoleAtLeast('admin');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Receipt ${transaction.transaction_number}`} />
            <div className="mx-auto flex min-h-full w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Link href={route('sales')} className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Back to Sales
                </Link>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-2">
                        <div>
                            <CardTitle className="tabular-nums">Receipt {transaction.transaction_number}</CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">{formatWhen(transaction.created_at)}</p>
                        </div>
                        <Badge variant="secondary">{PAYMENT_LABEL[transaction.payment_type] ?? transaction.payment_type}</Badge>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <Field label="Cashier" value={transaction.user?.name ?? transaction.user?.username ?? '—'} />
                            <Field label="Payment" value={PAYMENT_LABEL[transaction.payment_type] ?? transaction.payment_type} />
                            {transaction.comment && <Field label="Comment" value={transaction.comment} />}
                        </div>

                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Product</TableHead>
                                        <TableHead className="text-right">Qty</TableHead>
                                        <TableHead className="text-right">Unit Price</TableHead>
                                        {canViewCost && <TableHead className="text-right">Unit Cost</TableHead>}
                                        <TableHead className="text-right">Line Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transaction.items.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="font-medium">{line.label}</TableCell>
                                            <TableCell className="text-right tabular-nums">{line.quantity}</TableCell>
                                            <TableCell className="text-right tabular-nums">{peso(line.selling_price)}</TableCell>
                                            {canViewCost && (
                                                <TableCell className="text-right tabular-nums text-muted-foreground">
                                                    {peso(line.cost ?? null)}
                                                </TableCell>
                                            )}
                                            <TableCell className="text-right tabular-nums">{peso(line.total)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={canViewCost ? 4 : 3} className="text-right text-muted-foreground">
                                            Subtotal
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{peso(subtotalOf(transaction).toFixed(2))}</TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell colSpan={canViewCost ? 4 : 3} className="text-right text-muted-foreground">
                                            Discount
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{discountLabel(transaction)}</TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell colSpan={canViewCost ? 4 : 3} className="text-right font-semibold">
                                            Total
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">{peso(transaction.total)}</TableCell>
                                    </TableRow>
                                    {transaction.payment_type === 'cash' && (
                                        <>
                                            <TableRow>
                                                <TableCell colSpan={canViewCost ? 4 : 3} className="text-right text-muted-foreground">
                                                    Cash Received
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{peso(transaction.cash_received)}</TableCell>
                                            </TableRow>
                                            <TableRow>
                                                <TableCell colSpan={canViewCost ? 4 : 3} className="text-right text-muted-foreground">
                                                    Change
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{peso(transaction.change)}</TableCell>
                                            </TableRow>
                                        </>
                                    )}
                                </TableFooter>
                            </Table>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={printing}
                                onClick={() =>
                                    router.post(
                                        route('sales.print', transaction.id),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setPrinting(true),
                                            onFinish: () => setPrinting(false),
                                        },
                                    )
                                }
                            >
                                <Printer className="size-4" />
                                {printing ? 'Printing…' : 'Print receipt'}
                            </Button>
                            <Button variant="outline" size="sm" disabled={!hasReturnable} onClick={() => setDialogType('return')}>
                                <RotateCcw className="size-4" />
                                Return
                            </Button>
                            {canManageInventory && (
                                <Button variant="outline" size="sm" disabled={!hasReturnable} onClick={() => setDialogType('defect')}>
                                    <TriangleAlert className="size-4" />
                                    Defect
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {transaction.returns.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Returns &amp; adjustments</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {transaction.returns.map((event) => (
                                <ReturnEventCard key={event.id} event={event} canViewCost={canViewCost} />
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>

            {dialogType && (
                <ReturnDialog
                    open={dialogType !== null}
                    onOpenChange={(open) => !open && setDialogType(null)}
                    transaction={transaction}
                    type={dialogType}
                />
            )}
        </AppLayout>
    );
}

function ReturnEventCard({ event, canViewCost }: { event: SaleReturnEvent; canViewCost: boolean }) {
    return (
        <div className="rounded-lg border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
                <div className="flex items-center gap-2">
                    <Badge variant={RETURN_TYPE_BADGE[event.type]}>{RETURN_TYPE_LABEL[event.type]}</Badge>
                    <span className="text-sm font-medium tabular-nums">{event.return_number}</span>
                </div>
                <span className="text-xs text-muted-foreground">{formatWhen(event.created_at)}</span>
            </div>
            <div className="px-3 py-2">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Product</TableHead>
                            <TableHead className="text-right">Qty</TableHead>
                            {canViewCost && <TableHead className="text-right">Unit Cost</TableHead>}
                            <TableHead className="text-right">Refund</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {event.items.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell className="font-medium">{line.label}</TableCell>
                                <TableCell className="text-right tabular-nums">{line.quantity}</TableCell>
                                {canViewCost && (
                                    <TableCell className="text-right tabular-nums text-muted-foreground">{peso(line.cost ?? null)}</TableCell>
                                )}
                                <TableCell className="text-right tabular-nums">{peso(line.refund_amount)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={canViewCost ? 3 : 2} className="text-right font-semibold">
                                {event.type === 'defect' ? 'Written off' : 'Total refund'}
                            </TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">{peso(event.total_refund)}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
                <div className="mt-1 flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                    <span>By {event.performed_by?.name ?? event.performed_by?.username ?? '—'}</span>
                    {event.refund_payment_type && <span>via {PAYMENT_LABEL[event.refund_payment_type]}</span>}
                </div>
                {event.reason && <p className="mt-1 text-xs text-muted-foreground">Reason: {event.reason}</p>}
            </div>
        </div>
    );
}
