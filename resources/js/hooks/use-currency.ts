import { formatMoney } from '@/lib/money';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * The business currency symbol (shared Inertia prop) plus a formatter bound to
 * it. Components render money via `format(...)` so the symbol configured in
 * Business Settings applies everywhere.
 */
export function useCurrency() {
    const { currency } = usePage<SharedData>().props;

    const format = useCallback((value: number | string) => formatMoney(value, currency), [currency]);

    return { symbol: currency, format };
}
