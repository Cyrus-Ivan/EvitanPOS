import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Variant } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

type Mode = 'set' | 'delta';

type AdjustForm = {
    mode: Mode;
    value: string;
    notes: string;
};

/**
 * Manual stock adjustment dialog. Two modes:
 *  - "Add / Remove" (default): enter a signed change (+ add, − remove).
 *  - "Set to count": enter the true counted on-hand; the backend derives the delta.
 * Notes are optional except when setting a count, or when removing stock
 * (a negative add/remove) — both need an explanation for the ledger.
 */
export default function StockAdjustDialog({
    open,
    onOpenChange,
    variant,
    itemName,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    variant: Variant | null;
    itemName?: string;
}) {
    const form = useForm<AdjustForm>({ mode: 'delta', value: '', notes: '' });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    // Reset on open so values/errors never leak between variants.
    useEffect(() => {
        if (open) {
            setData({ mode: 'delta', value: '', notes: '' });
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, variant?.id]);

    const current = variant?.current_stock ?? 0;

    // Notes are mandatory when setting a count or when removing stock.
    const notesRequired = data.mode === 'set' || Number(data.value) < 0;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!variant) {
            return;
        }
        form.post(route('stock.adjust', variant.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    // Preview the resulting on-hand so the user sees the effect before saving.
    const parsed = Number(data.value);
    const hasValue = data.value !== '' && Number.isFinite(parsed);
    const resulting = hasValue ? (data.mode === 'set' ? parsed : current + parsed) : null;

    // In add/remove, zero is not a change: a positive value adds, a negative removes.
    const deltaIsZero = data.mode === 'delta' && hasValue && parsed === 0;
    const canSubmit = hasValue && !deltaIsZero;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Adjust stock</DialogTitle>
                        <DialogDescription>
                            {variant ? (
                                <>
                                    {itemName ? `${itemName} — ` : ''}
                                    {variant.name} · current on-hand <span className="text-foreground font-medium tabular-nums">{current}</span>
                                </>
                            ) : null}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label>Mode</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={data.mode}
                            onValueChange={(value) => value && setData('mode', value as Mode)}
                            className="justify-start"
                        >
                            <ToggleGroupItem value="delta">Add / Remove</ToggleGroupItem>
                            <ToggleGroupItem value="set">Set to count</ToggleGroupItem>
                        </ToggleGroup>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adjust-value">{data.mode === 'set' ? 'Counted quantity' : 'Change (+ add, − remove)'}</Label>
                        <Input
                            id="adjust-value"
                            type="number"
                            inputMode="numeric"
                            step={1}
                            min={data.mode === 'set' ? 0 : undefined}
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                            required
                            autoFocus
                            placeholder={data.mode === 'set' ? 'e.g. 37' : 'e.g. -3 or 10'}
                        />
                        {deltaIsZero ? (
                            <p className="text-destructive text-sm">Enter a positive number to add stock or a negative number to remove it.</p>
                        ) : (
                            resulting !== null && (
                                <p className="text-muted-foreground text-sm">
                                    New on-hand: <span className="text-foreground font-medium tabular-nums">{resulting}</span>
                                </p>
                            )
                        )}
                        <InputError message={errors.value} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adjust-notes">
                            Notes{' '}
                            {notesRequired ? (
                                <span className="text-muted-foreground">(required)</span>
                            ) : (
                                <span className="text-muted-foreground">(optional)</span>
                            )}
                        </Label>
                        <Textarea
                            id="adjust-notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            required={notesRequired}
                            rows={2}
                            placeholder="e.g. Physical recount, damaged goods…"
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing || !canSubmit}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save adjustment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
