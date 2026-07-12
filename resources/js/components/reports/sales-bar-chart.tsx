import { formatPeso } from '@/lib/money';
import type { ReportPeriod, ReportRow } from '@/types';
import { useState } from 'react';

// A self-contained SVG bar chart of net sales per bucket (Phase 12) — no charting
// dependency. Single series over time, so no legend: the caption names it. Net can
// go negative (a period whose returns/defects exceed its sales), so the chart has a
// zero baseline with positive bars in the chart hue and negative bars in the
// destructive hue. All colors are theme tokens (fill-* utilities), so light/dark
// track the app automatically.

// viewBox coordinate space; the SVG scales to its container width.
const VB_W = 720;
const VB_H = 260;
const PAD_L = 52;
const PAD_R = 12;
const PAD_T = 16;
const PAD_B = 30;
const PLOT_W = VB_W - PAD_L - PAD_R;
const PLOT_H = VB_H - PAD_T - PAD_B;

// Compact peso for axis ticks: ₱1.2k / ₱3.4M, sign preserved.
function compactPeso(value: number): string {
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value);
    if (abs >= 1_000_000) return `${sign}₱${(abs / 1_000_000).toFixed(1)}M`;
    if (abs >= 1_000) return `${sign}₱${(abs / 1_000).toFixed(1)}k`;
    return `${sign}₱${Math.round(abs)}`;
}

// Bucket → short axis label. Daily bucket is 'YYYY-MM-DD' (show the day),
// monthly is 'YYYY-MM' (show the month abbreviation).
function shortLabel(bucket: string, period: ReportPeriod): string {
    if (period === 'monthly') {
        const [, month] = bucket.split('-');
        return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(month)] ?? bucket;
    }
    return bucket.split('-')[2] ?? bucket;
}

// Bucket → full label for the tooltip / accessible title.
function fullLabel(bucket: string, period: ReportPeriod): string {
    const date = new Date(period === 'monthly' ? `${bucket}-01` : bucket);
    if (Number.isNaN(date.getTime())) return bucket;
    return period === 'monthly'
        ? date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
        : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function SalesBarChart({ rows, period }: { rows: ReportRow[]; period: ReportPeriod }) {
    const [hover, setHover] = useState<number | null>(null);

    if (rows.length === 0) {
        return null;
    }

    const nets = rows.map((r) => Number(r.net_sales) || 0);
    const maxNet = Math.max(0, ...nets);
    const minNet = Math.min(0, ...nets);
    // Guard the all-zero case so the domain isn't degenerate.
    const domainMax = maxNet === 0 && minNet === 0 ? 1 : maxNet;

    const y = (value: number) => {
        const span = domainMax - minNet || 1;
        return PAD_T + PLOT_H - ((value - minNet) / span) * PLOT_H;
    };
    const zeroY = y(0);

    const slot = PLOT_W / rows.length;
    const barW = Math.min(slot * 0.62, 40);
    // Label every bucket when there's room, else thin them out to ~12 labels.
    const labelEvery = Math.ceil(rows.length / 12);

    // Gridlines: top, zero, and bottom (when there are negative values).
    const gridValues = minNet < 0 ? [domainMax, 0, minNet] : [domainMax, 0];

    return (
        <div className="relative w-full">
            <svg viewBox={`0 0 ${VB_W} ${VB_H}`} className="w-full" role="img" aria-label="Net sales per period" preserveAspectRatio="none">
                {/* Gridlines + y tick labels. */}
                {gridValues.map((value) => (
                    <g key={value}>
                        <line
                            x1={PAD_L}
                            x2={VB_W - PAD_R}
                            y1={y(value)}
                            y2={y(value)}
                            className={value === 0 ? 'stroke-border' : 'stroke-border/60'}
                            strokeWidth={1}
                            strokeDasharray={value === 0 ? undefined : '3 3'}
                        />
                        <text x={PAD_L - 8} y={y(value) + 3} textAnchor="end" className="fill-muted-foreground text-[10px]">
                            {compactPeso(value)}
                        </text>
                    </g>
                ))}

                {/* Bars. */}
                {rows.map((row, i) => {
                    const net = nets[i];
                    const cx = PAD_L + slot * (i + 0.5);
                    const top = net >= 0 ? y(net) : zeroY;
                    const height = Math.max(1, Math.abs(zeroY - y(net)));
                    const active = hover === i;
                    return (
                        <g key={row.bucket}>
                            <rect
                                x={cx - barW / 2}
                                y={top}
                                width={barW}
                                height={height}
                                rx={2}
                                className={net >= 0 ? 'fill-chart-1' : 'fill-destructive'}
                                opacity={hover === null || active ? 1 : 0.55}
                            />
                            {/* Full-height hit target so hover is easy on thin bars. */}
                            <rect
                                x={cx - slot / 2}
                                y={PAD_T}
                                width={slot}
                                height={PLOT_H}
                                fill="transparent"
                                onMouseEnter={() => setHover(i)}
                                onMouseLeave={() => setHover(null)}
                            >
                                <title>{`${fullLabel(row.bucket, period)}: ${formatPeso(row.net_sales)}`}</title>
                            </rect>
                            {i % labelEvery === 0 && (
                                <text x={cx} y={VB_H - 10} textAnchor="middle" className="fill-muted-foreground text-[10px]">
                                    {shortLabel(row.bucket, period)}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>

            {/* HTML tooltip: positioned by the hovered bar's fractional x within the plot. */}
            {hover !== null && (
                <div
                    className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border bg-popover px-3 py-2 text-xs shadow-md"
                    style={{ left: `${((PAD_L + slot * (hover + 0.5)) / VB_W) * 100}%` }}
                >
                    <div className="font-medium text-popover-foreground">{fullLabel(rows[hover].bucket, period)}</div>
                    <dl className="mt-1 grid grid-cols-[auto_auto] gap-x-3 gap-y-0.5 tabular-nums text-muted-foreground">
                        <dt>Sales</dt>
                        <dd className="text-right text-popover-foreground">{formatPeso(rows[hover].gross_sales)}</dd>
                        <dt>Returns</dt>
                        <dd className="text-right text-popover-foreground">−{formatPeso(rows[hover].returns)}</dd>
                        <dt>Defects</dt>
                        <dd className="text-right text-popover-foreground">−{formatPeso(rows[hover].defects)}</dd>
                        <dt className="font-medium">Net</dt>
                        <dd className="text-right font-medium text-popover-foreground">{formatPeso(rows[hover].net_sales)}</dd>
                    </dl>
                </div>
            )}
        </div>
    );
}
