import { formatPeso } from '@/lib/money';
import type { ReportChartBucket, ReportChartItem, ReportPeriod } from '@/types';
import { useState } from 'react';

// A self-contained SVG stacked bar chart of net sales per bucket, broken down by
// item (Phase 12) — no charting dependency. Each bar stacks one segment per item
// (all its variants aggregated); the top items keep their own segment and the rest
// fold into "Other" server-side. Net can go negative for an item in a bucket (its
// returns/defects exceed its sales), so this is a DIVERGING stacked chart: positive
// segments stack up from the zero baseline, negative ones stack down. Colors are a
// CVD-validated categorical palette (fill-chart-1..8, dataviz skill); "Other" is a
// neutral muted tone. All hues are theme tokens, so light/dark track the app.

// viewBox coordinate space; the SVG scales to its container width.
const VB_W = 720;
const VB_H = 260;
const PAD_L = 52;
const PAD_R = 12;
const PAD_T = 16;
const PAD_B = 30;
const PLOT_W = VB_W - PAD_L - PAD_R;
const PLOT_H = VB_H - PAD_T - PAD_B;

// A 2px surface gap between stacked fills (marks spec) so adjacent segments read
// as distinct even for viewers who can't separate the hues.
const GAP = 2;

const OTHER_KEY = '__other__';

// Categorical fills by legend position; "Other" always sits last on a neutral tone.
// Listed literally so Tailwind emits the utilities.
const SERIES_FILL = ['fill-chart-1', 'fill-chart-2', 'fill-chart-3', 'fill-chart-4', 'fill-chart-5', 'fill-chart-6', 'fill-chart-7', 'fill-chart-8'];
const SERIES_SWATCH = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4', 'bg-chart-5', 'bg-chart-6', 'bg-chart-7', 'bg-chart-8'];

function fillFor(item: ReportChartItem, index: number): string {
    return item.key === OTHER_KEY ? 'fill-muted-foreground' : (SERIES_FILL[index] ?? 'fill-muted-foreground');
}
function swatchFor(item: ReportChartItem, index: number): string {
    return item.key === OTHER_KEY ? 'bg-muted-foreground' : (SERIES_SWATCH[index] ?? 'bg-muted-foreground');
}

// Compact peso for axis ticks: ₱1.2k / ₱3.4M, sign preserved.
function compactPeso(value: number): string {
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value);
    if (abs >= 1_000_000) return `${sign}₱${(abs / 1_000_000).toFixed(1)}M`;
    if (abs >= 1_000) return `${sign}₱${(abs / 1_000).toFixed(1)}k`;
    return `${sign}₱${Math.round(abs)}`;
}

const MONTH_ABBR = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Sub-bucket → short axis label, matching the server's drill-down granularity:
// daily 'YYYY-MM-DD HH' → hour ('8a'); weekly/monthly 'YYYY-MM-DD' → day-of-month;
// yearly 'YYYY-MM' → month abbrev.
function shortLabel(bucket: string, period: ReportPeriod): string {
    const parts = bucket.split('-');
    if (period === 'daily') {
        const h = Number(bucket.slice(11, 13));
        if (Number.isNaN(h)) return bucket;
        const suffix = h < 12 ? 'a' : 'p';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12}${suffix}`;
    }
    if (period === 'yearly') return MONTH_ABBR[Number(parts[1])] ?? bucket;
    return String(Number(parts[2])) || bucket; // day-of-month
}

// Sub-bucket → full label for the tooltip / accessible title.
function fullLabel(bucket: string, period: ReportPeriod): string {
    if (period === 'daily') {
        const date = new Date(`${bucket.slice(0, 10)}T${bucket.slice(11, 13)}:00:00`);
        if (Number.isNaN(date.getTime())) return bucket;
        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    if (period === 'yearly') {
        const date = new Date(`${bucket}-01`);
        return Number.isNaN(date.getTime()) ? bucket : date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' });
    }
    const date = new Date(bucket);
    return Number.isNaN(date.getTime())
        ? bucket
        : date.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
}

export default function SalesBarChart({ chart, items, period }: { chart: ReportChartBucket[]; items: ReportChartItem[]; period: ReportPeriod }) {
    const [hover, setHover] = useState<number | null>(null);

    if (chart.length === 0 || items.length === 0) {
        return null;
    }

    // Per bucket: the ordered item values, plus the positive/negative stack sums
    // that set the diverging Y-domain.
    const columns = chart.map((bucket) => {
        const values = items.map((it) => Number(bucket.values[it.key] ?? 0) || 0);
        const posSum = values.reduce((s, v) => (v > 0 ? s + v : s), 0);
        const negSum = values.reduce((s, v) => (v < 0 ? s + v : s), 0);
        const net = values.reduce((s, v) => s + v, 0);
        return { bucket: bucket.bucket, values, posSum, negSum, net };
    });

    const domainMaxRaw = Math.max(0, ...columns.map((c) => c.posSum));
    const domainMin = Math.min(0, ...columns.map((c) => c.negSum));
    // Guard the all-zero case so the domain isn't degenerate.
    const domainMax = domainMaxRaw === 0 && domainMin === 0 ? 1 : domainMaxRaw;

    const y = (value: number) => {
        const span = domainMax - domainMin || 1;
        return PAD_T + PLOT_H - ((value - domainMin) / span) * PLOT_H;
    };

    const slot = PLOT_W / columns.length;
    const barW = Math.min(slot * 0.62, 40);
    // Label every bucket when there's room, else thin them out to ~12 labels.
    const labelEvery = Math.ceil(columns.length / 12);

    // Gridlines: top, zero, and bottom (only when there are negative stacks).
    const gridValues = domainMin < 0 ? [domainMax, 0, domainMin] : [domainMax, 0];

    return (
        <div className="relative w-full">
            <svg viewBox={`0 0 ${VB_W} ${VB_H}`} className="w-full" role="img" aria-label="Net sales by item per period" preserveAspectRatio="none">
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

                {/* Stacked bars. */}
                {columns.map((col, i) => {
                    const cx = PAD_L + slot * (i + 0.5);
                    const active = hover === i;
                    let cumPos = 0; // running top of the positive stack
                    let cumNeg = 0; // running bottom of the negative stack

                    return (
                        <g key={col.bucket} opacity={hover === null || active ? 1 : 0.55}>
                            {items.map((item, s) => {
                                const value = col.values[s];
                                if (value === 0) return null;

                                let top: number;
                                let height: number;
                                if (value > 0) {
                                    top = y(cumPos + value);
                                    height = y(cumPos) - top;
                                    cumPos += value;
                                } else {
                                    top = y(cumNeg);
                                    height = y(cumNeg + value) - top;
                                    cumNeg += value;
                                }
                                // Inset a surface gap without collapsing tiny segments.
                                const drawH = Math.max(1, height - GAP);
                                return (
                                    <rect
                                        key={item.key}
                                        x={cx - barW / 2}
                                        y={top + (height - drawH) / 2}
                                        width={barW}
                                        height={drawH}
                                        rx={1.5}
                                        className={fillFor(item, s)}
                                    />
                                );
                            })}

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
                                <title>{`${fullLabel(col.bucket, period)}: ${formatPeso(col.net.toFixed(2))}`}</title>
                            </rect>

                            {i % labelEvery === 0 && (
                                <text x={cx} y={VB_H - 10} textAnchor="middle" className="fill-muted-foreground text-[10px]">
                                    {shortLabel(col.bucket, period)}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>

            {/* Legend — identity is never color-alone. */}
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                {items.map((item, s) => (
                    <div key={item.key} className="text-muted-foreground flex items-center gap-1.5 text-xs">
                        <span className={'inline-block size-2.5 rounded-[3px] ' + swatchFor(item, s)} />
                        {item.name}
                    </div>
                ))}
            </div>

            {/* HTML tooltip: positioned by the hovered bar's fractional x within the plot. */}
            {hover !== null && (
                <div
                    className="bg-popover pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border px-3 py-2 text-xs shadow-md"
                    style={{ left: `${((PAD_L + slot * (hover + 0.5)) / VB_W) * 100}%` }}
                >
                    <div className="text-popover-foreground font-medium">{fullLabel(columns[hover].bucket, period)}</div>
                    <dl className="text-muted-foreground mt-1 grid grid-cols-[auto_1fr_auto] items-center gap-x-2 gap-y-0.5 tabular-nums">
                        {items.map((item, s) => {
                            const value = columns[hover].values[s];
                            if (value === 0) return null;
                            return (
                                <div key={item.key} className="contents">
                                    <span className={'inline-block size-2 rounded-[2px] ' + swatchFor(item, s)} />
                                    <dt className="truncate">{item.name}</dt>
                                    <dd className="text-popover-foreground text-right">{formatPeso(value.toFixed(2))}</dd>
                                </div>
                            );
                        })}
                        <span />
                        <dt className="font-medium">Net</dt>
                        <dd className="text-popover-foreground text-right font-medium">{formatPeso(columns[hover].net.toFixed(2))}</dd>
                    </dl>
                </div>
            )}
        </div>
    );
}
