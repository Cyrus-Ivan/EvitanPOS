/**
 * Format a money amount for display with the business currency symbol. Money
 * arrives from the backend as decimal strings (Eloquent decimal casts), so
 * coerce to a number first; a non-numeric value formats as {symbol}0.00 rather
 * than NaN.
 */
export function formatMoney(value: number | string, symbol: string = '₱'): string {
    const amount = Number(value);
    const safe = Number.isFinite(amount) ? amount : 0;
    const digits = Math.abs(safe).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return `${safe < 0 ? '-' : ''}${symbol}${digits}`;
}

/**
 * Compact money for chart axis ticks: {symbol}1.2k / {symbol}3.4M, sign
 * preserved.
 */
export function formatMoneyCompact(value: number, symbol: string = '₱'): string {
    const abs = Math.abs(value);
    const sign = value < 0 ? '-' : '';

    if (abs >= 1_000_000) return `${sign}${symbol}${(abs / 1_000_000).toFixed(1)}M`;
    if (abs >= 1_000) return `${sign}${symbol}${(abs / 1_000).toFixed(1)}k`;
    return `${sign}${symbol}${Math.round(abs)}`;
}
