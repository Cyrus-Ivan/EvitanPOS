const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Format a peso amount for display. Money arrives from the backend as decimal
 * strings (Eloquent decimal casts), so coerce to a number first; a non-numeric
 * value formats as ₱0.00 rather than NaN.
 */
export function formatPeso(value: number | string): string {
    const amount = Number(value);
    return peso.format(Number.isFinite(amount) ? amount : 0);
}
