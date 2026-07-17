import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import type { PaymentType, SaleReturnType, TransactionDetail } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

const REFUND_METHODS: { value: PaymentType; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'cashless', label: 'Cashless' },
    { value: 'others', label: 'Others' },
];

const COPY: Record<SaleReturnType, { title: string; description: string; action: string }> = {
    return: {
        title: 'Process return',
        description: 'Select the quantities to return. Stock is restored and the customer is refunded at the original price.',
        action: 'Process return',
    },
    defect: {
        title: 'Record defect',
        description: 'Marks quantities as defective write-offs. Stock is NOT restored and no money is returned to the customer.',
        action: 'Record defect',
    },
};

/**
 * The after-sales modal (Phase 10). Drives return / defect off one form. Both
 * present a per-line quantity capped at what remains (sold − already returned).
 * A defect returns no money, so it hides the refund-method select.
 */
export default function ReturnDialog({
    open,
    onOpenChange,
    transaction,
    type,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    transaction: TransactionDetail;
    type: SaleReturnType;
}) {
    const copy = COPY[type];
    const isDefect = type === 'defect';

    // Lines with quantity still available to act on.
    const remainingLines = transaction.items
        .map((line) => ({ line, remaining: line.quantity - line.returned_quantity }))
        .filter((row) => row.remaining > 0);

    const form = useForm<{ reason: string; refund_payment_type: PaymentType }>({
        reason: '',
        refund_payment_type: 'cash',
    });
    const { data, setData, errors, processing, clearErrors, reset, transform } = form;

    const [quantities, setQuantities] = useState<Record<string, number>>({});

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
            setQuantities({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const setQuantity = (id: string, value: string, max: number) => {
        const parsed = Math.max(0, Math.min(max, Math.floor(Number(value) || 0)));
        setQuantities((prev) => ({ ...prev, [id]: parsed }));
    };

    const selectedCount = Object.values(quantities).reduce((sum, q) => sum + q, 0);
    const canSubmit = selectedCount > 0;

    // Server-side line failures are keyed lines.N.quantity etc. (only reachable
    // on stale data, e.g. a concurrent return in another window), so surface the
    // first nested message rather than dropping it.
    const errorBag = errors as Record<string, string | undefined>;
    const lineError = errorBag.lines ?? Object.entries(errorBag).find(([key]) => key.startsWith('lines.'))?.[1];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        transform((current) => ({
            type,
            reason: current.reason,
            refund_payment_type: isDefect ? null : current.refund_payment_type,
            lines: remainingLines
                .map(({ line }) => ({ transaction_item_id: line.id, quantity: quantities[line.id] ?? 0 }))
                .filter((line) => line.quantity > 0),
        }));

        form.post(route('sales.returns.store', transaction.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{copy.title}</DialogTitle>
                        <DialogDescription>{copy.description}</DialogDescription>
                    </DialogHeader>

                    <div className="rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Product</TableHead>
                                    <TableHead className="text-right">Remaining</TableHead>
                                    <TableHead className="w-28 text-right">Qty</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {remainingLines.map(({ line, remaining }) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="font-medium">{line.label}</TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">{remaining}</TableCell>
                                        <TableCell className="text-right">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={remaining}
                                                value={quantities[line.id] ?? 0}
                                                onChange={(e) => setQuantity(line.id, e.target.value, remaining)}
                                                className="text-right tabular-nums"
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {remainingLines.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="text-muted-foreground text-center text-sm">
                                            Every line has already been fully returned.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    <InputError message={lineError ?? errorBag.type} />

                    {!isDefect && (
                        <div className="grid gap-2">
                            <Label htmlFor="refund-method">Refund method</Label>
                            <Select value={data.refund_payment_type} onValueChange={(value) => setData('refund_payment_type', value as PaymentType)}>
                                <SelectTrigger id="refund-method">
                                    <SelectValue placeholder="Select a refund method" />
                                </SelectTrigger>
                                <SelectContent>
                                    {REFUND_METHODS.map((method) => (
                                        <SelectItem key={method.value} value={method.value}>
                                            {method.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.refund_payment_type} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="return-reason">Reason (optional)</Label>
                        <Textarea
                            id="return-reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            maxLength={500}
                            rows={2}
                            placeholder="e.g. wrong size, damaged on arrival"
                        />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing || !canSubmit}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {copy.action}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
