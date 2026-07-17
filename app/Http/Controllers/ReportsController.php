<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\Settings\BusinessSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business reporting (Phase 12). Read-only. Daily / Weekly / Monthly / Yearly
 * sales reports that live on one page, switched by an in-page `period` toggle.
 *
 * Each period reports on a SINGLE selected period (one day / week / month /
 * year), resolved from an anchor `date` into a `[from, to]` window. Within that
 * window the chart bars and table rows drill DOWN one level — a day breaks into
 * hourly buckets, a week/month into daily buckets, a year into monthly buckets
 * (see {@see self::SUB_FORMATS}). The user steps between periods with the
 * prev/next anchors returned in `filters`.
 *
 * Everything is RECOMPUTED from the append-only source tables — never a stored
 * total (not `transactions.total` as a period sum shortcut, never
 * `sale_returns.total_refund`). Net sales = sales − returns − defects, per
 * `CLAUDE.md` → "Reporting" and docs/returns-refunds-voids-design.md.
 *
 * Access is Admin+ (route-level `role:admin`). Cost / COGS / profit are
 * Owner-only: the keys are omitted entirely from the payload for non-Owners,
 * mirroring SalesController::show.
 */
class ReportsController extends Controller
{
    /**
     * The drill-down (sub-bucket) SQLite strftime format per selected period —
     * one level finer than the selection itself, so a single period still yields
     * a legible multi-bar chart: a day → hours, a week/month → days, a year →
     * months. Also the set of valid period keys ({@see self::normalizePeriod}).
     */
    private const SUB_FORMATS = [
        'daily' => '%Y-%m-%d %H', // hourly
        'weekly' => '%Y-%m-%d',   // daily
        'monthly' => '%Y-%m-%d',  // daily
        'yearly' => '%Y-%m',      // monthly
    ];

    /** The sub-bucket's granularity noun, used as the breakdown table's first column header. */
    private const SUB_NOUNS = [
        'daily' => 'Hour',
        'weekly' => 'Day',
        'monthly' => 'Day',
        'yearly' => 'Month',
    ];

    /** How many items get their own stacked-chart segment before the rest fold into "Other". */
    private const CHART_TOP_ITEMS = 8;

    /** Legend/segment key for the aggregated tail of low-revenue items. */
    private const OTHER_KEY = '__other__';

    public function index(Request $request): Response
    {
        $period = $this->normalizePeriod($request->query('period'));
        [$from, $to] = $this->resolveWindow($period, $this->normalizeDate($request->query('date')));
        $canViewCost = Gate::allows('view-item-cost');

        $rows = $this->aggregate($period, $from, $to, $canViewCost);
        $chart = $this->buildChart($period, $from, $to);

        return Inertia::render('reports', [
            'period' => $period,
            'rows' => $rows,
            'totals' => $this->sumTotals($rows, $canViewCost),
            'chartItems' => $chart['items'],
            'chart' => $chart['buckets'],
            'canViewCost' => $canViewCost,
            'filters' => $this->filters($period, $from),
        ]);
    }

    /**
     * Render the selected period's report as a downloadable PDF (dompdf, pure
     * PHP — no browser binary, safe for the offline NativePHP build). Reuses the
     * exact same recompute path as {@see self::index}, so the PDF matches the
     * on-screen totals. Cost / COGS / profit stay Owner-only: the flag is passed
     * to the view, which omits those columns entirely for non-Owners.
     *
     * The PDF carries a net-sales bar chart (one diverging bar per sub-bucket,
     * built from HTML divs — dompdf's inline-SVG support is unreliable), the
     * summary totals, the breakdown table, and a "Top selling items" table for
     * the whole selected range ({@see self::topItems}, cost-free so safe for all
     * Admin+ viewers).
     */
    public function export(Request $request, BusinessSettings $settings): HttpResponse
    {
        $period = $this->normalizePeriod($request->query('period'));
        [$from, $to] = $this->resolveWindow($period, $this->normalizeDate($request->query('date')));
        $canViewCost = Gate::allows('view-item-cost');

        $rows = array_map(function (array $row) use ($period): array {
            $row['label'] = $this->formatBucketLabel($period, $row['bucket']);

            return $row;
        }, $this->aggregate($period, $from, $to, $canViewCost));

        $pdf = Pdf::loadView('reports.pdf', [
            'business' => $settings->all(),
            'period' => $period,
            'periodNoun' => self::SUB_NOUNS[$period],
            'label' => $this->periodLabel($period, $from),
            'generatedAt' => Carbon::now(),
            'rows' => $rows,
            'totals' => $this->sumTotals($rows, $canViewCost),
            'topItems' => $this->topItems($from, $to),
            'canViewCost' => $canViewCost,
        ]);

        $filename = 'sales-report-'.$period.'-'.$from->toDateString().'-'.Carbon::now()->format('Ymd-His').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Build one ordered (chronological) row per bucket in the range, recomputing
     * sales, returns and defects from source tables and deriving net (and, for
     * the Owner, COGS + gross profit). All money is reconciled through integer
     * centavos so the sums are cent-exact despite SQLite's float SUM().
     *
     * @return list<array<string, mixed>>
     */
    private function aggregate(string $period, Carbon $dateFrom, Carbon $dateTo, bool $canViewCost): array
    {
        $format = self::SUB_FORMATS[$period];
        $from = $dateFrom->copy();
        $to = $dateTo->copy();

        // Sales & transaction count, bucketed by the sale's own date.
        $sales = Transaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('strftime(?, created_at) as bucket, COUNT(*) as txns, SUM(total) as gross', [$format])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        // Returns and defects, bucketed by the EVENT date (sale_returns.created_at)
        // — an after-sales event reduces the period it happened in.
        $returns = $this->refundsByBucket('return', $format, $from, $to);
        $defects = $this->refundsByBucket('defect', $format, $from, $to);

        // COGS + profit are Owner-only. Sold cost is attributed to the sale's date;
        // the cost of RETURNED goods is credited back (they re-enter stock) on the
        // return's event date. Defected goods are written off — their cost stays in
        // COGS. Items with NO recorded cost can't yield a margin, so their net sales
        // are split out into `uncosted_sales` and excluded from profit entirely
        // (rather than silently treated as zero-cost, which would inflate profit).
        $soldCost = $canViewCost ? $this->soldCostByBucket($format, $from, $to) : collect();
        $returnedCost = $canViewCost ? $this->costByBucket('return', $format, $from, $to) : collect();
        $uncostedRevenue = $canViewCost ? $this->uncostedRevenueByBucket($format, $from, $to) : collect();
        $uncostedRefund = $canViewCost ? $this->uncostedRefundByBucket($format, $from, $to) : collect();

        $buckets = collect([$sales, $returns, $defects, $soldCost, $returnedCost, $uncostedRevenue, $uncostedRefund])
            ->flatMap(fn ($map) => $map->keys())
            ->unique()
            ->sort()
            ->values();

        return $buckets->map(function (string $bucket) use ($sales, $returns, $defects, $soldCost, $returnedCost, $uncostedRevenue, $uncostedRefund, $canViewCost) {
            $gross = $this->centavos($sales[$bucket]->gross ?? 0);
            $returnRefund = $this->centavos($returns[$bucket] ?? 0);
            $defectRefund = $this->centavos($defects[$bucket] ?? 0);
            $net = $gross - $returnRefund - $defectRefund;

            $row = [
                'bucket' => $bucket,
                'transactions' => (int) ($sales[$bucket]->txns ?? 0),
                'gross_sales' => $this->pesos($gross),
                'returns' => $this->pesos($returnRefund),
                'defects' => $this->pesos($defectRefund),
                'net_sales' => $this->pesos($net),
            ];

            if ($canViewCost) {
                // Net sales attributable to un-costed items (their sale revenue,
                // discount-apportioned, less any of their own refunds). Whatever is
                // left is the costed portion that profit is computed over.
                $uncostedNet = $this->centavos($uncostedRevenue[$bucket] ?? 0) - $this->centavos($uncostedRefund[$bucket] ?? 0);
                $costedNet = $net - $uncostedNet;

                // Net COGS excludes returned goods (credited back); defected goods'
                // cost is retained (written off, not restored to stock).
                $cogs = $this->centavos($soldCost[$bucket] ?? 0) - $this->centavos($returnedCost[$bucket] ?? 0);

                $row['cogs'] = $this->pesos($cogs);
                // Profit covers costed items only; un-costed sales are reported apart.
                $row['profit'] = $this->pesos($costedNet - $cogs);
                $row['uncosted_sales'] = $this->pesos($uncostedNet);
            }

            return $row;
        })->all();
    }

    /**
     * SUM(refund_amount) per bucket for one after-sales type, keyed by the event
     * date bucket.
     *
     * @return Collection<string, float>
     */
    private function refundsByBucket(string $type, string $format, Carbon $from, Carbon $to): Collection
    {
        return DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->where('sr.type', $type)
            ->whereBetween('sr.created_at', [$from, $to])
            ->selectRaw('strftime(?, sr.created_at) as bucket, SUM(sri.refund_amount) as refund', [$format])
            ->groupBy('bucket')
            ->pluck('refund', 'bucket');
    }

    /**
     * SUM(cost * quantity) of sold lines per bucket, keyed by the sale's date.
     *
     * @return Collection<string, float>
     */
    private function soldCostByBucket(string $format, Carbon $from, Carbon $to): Collection
    {
        return DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->whereBetween('t.created_at', [$from, $to])
            ->selectRaw('strftime(?, t.created_at) as bucket, SUM(ti.cost * ti.quantity) as cost', [$format])
            ->groupBy('bucket')
            ->pluck('cost', 'bucket');
    }

    /**
     * SUM(cost * quantity) of after-sales lines of one type per bucket, keyed by
     * the event date.
     *
     * @return Collection<string, float>
     */
    private function costByBucket(string $type, string $format, Carbon $from, Carbon $to): Collection
    {
        return DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->where('sr.type', $type)
            ->whereBetween('sr.created_at', [$from, $to])
            ->selectRaw('strftime(?, sr.created_at) as bucket, SUM(sri.cost * sri.quantity) as cost', [$format])
            ->groupBy('bucket')
            ->pluck('cost', 'bucket');
    }

    /**
     * Sale revenue of un-costed lines (cost IS NULL) per bucket, keyed by the
     * sale's date. Each line's revenue is its share of the transaction total
     * (line total ÷ transaction subtotal × transaction total), so any
     * transaction-level discount is apportioned and the costed + un-costed split
     * reconciles exactly to net sales.
     *
     * @return Collection<string, float>
     */
    private function uncostedRevenueByBucket(string $format, Carbon $from, Carbon $to): Collection
    {
        $subtotals = DB::table('transaction_items')
            ->select('transaction_id')
            ->selectRaw('SUM(total) as subtotal')
            ->groupBy('transaction_id');

        return DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->joinSub($subtotals, 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->whereNull('ti.cost')
            ->where('sub.subtotal', '>', 0)
            ->whereBetween('t.created_at', [$from, $to])
            ->selectRaw('strftime(?, t.created_at) as bucket, SUM(ti.total * t.total / sub.subtotal) as revenue', [$format])
            ->groupBy('bucket')
            ->pluck('revenue', 'bucket');
    }

    /**
     * SUM(refund_amount) of un-costed after-sales lines (cost IS NULL, both
     * returns and defects) per bucket, keyed by the event date. Mirrors the
     * un-costed revenue split on the refund side.
     *
     * @return Collection<string, float>
     */
    private function uncostedRefundByBucket(string $format, Carbon $from, Carbon $to): Collection
    {
        return DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->whereNull('sri.cost')
            ->whereBetween('sr.created_at', [$from, $to])
            ->selectRaw('strftime(?, sr.created_at) as bucket, SUM(sri.refund_amount) as refund', [$format])
            ->groupBy('bucket')
            ->pluck('refund', 'bucket');
    }

    /**
     * Build the stacked-bar chart payload: per bucket, each item's NET sales
     * (its discount-apportioned revenue minus its own returns + defects) as a
     * separate segment. Segments reconcile to the table's net per bucket because
     * per-item revenue is apportioned exactly like the un-costed split, so the
     * sum over items of a bucket equals its net_sales.
     *
     * Only the top {@see self::CHART_TOP_ITEMS} items by gross revenue keep their
     * own segment; the rest fold into a single "Other" segment so the chart stays
     * legible for large catalogs. Net can be negative (an item whose returns in a
     * bucket exceed its sales), which the frontend renders as a downward segment.
     *
     * Item cost is never exposed here (net = revenue − refunds), so this is safe
     * for every Admin+ viewer regardless of the Owner-only cost gate.
     *
     * @return array{items: list<array{key: string, name: string}>, buckets: list<array{bucket: string, values: object}>}
     */
    private function buildChart(string $period, Carbon $dateFrom, Carbon $dateTo): array
    {
        $format = self::SUB_FORMATS[$period];
        $from = $dateFrom->copy();
        $to = $dateTo->copy();

        $revenueRows = $this->itemRevenueByBucket($format, $from, $to);
        $refundRows = $this->itemRefundByBucket($format, $from, $to);

        $names = [];          // item_id => current name
        $totalRevenue = [];   // item_id => gross revenue centavos (ranking key)
        $net = [];            // item_id => [bucket => net centavos]
        $bucketSet = [];      // bucket => true

        foreach ($revenueRows as $row) {
            $cents = $this->centavos($row->revenue);
            $names[$row->item_id] = $row->item_name;
            $totalRevenue[$row->item_id] = ($totalRevenue[$row->item_id] ?? 0) + $cents;
            $net[$row->item_id][$row->bucket] = ($net[$row->item_id][$row->bucket] ?? 0) + $cents;
            $bucketSet[$row->bucket] = true;
        }

        foreach ($refundRows as $row) {
            $cents = $this->centavos($row->refund);
            $names[$row->item_id] = $row->item_name;
            $totalRevenue[$row->item_id] ??= 0;
            $net[$row->item_id][$row->bucket] = ($net[$row->item_id][$row->bucket] ?? 0) - $cents;
            $bucketSet[$row->bucket] = true;
        }

        if ($net === []) {
            return ['items' => [], 'buckets' => []];
        }

        // Rank by gross revenue desc, name asc as a stable tie-break.
        $ranked = array_keys($totalRevenue);
        usort($ranked, fn ($a, $b) => ($totalRevenue[$b] <=> $totalRevenue[$a]) ?: strcmp($names[$a], $names[$b]));

        $kept = array_slice($ranked, 0, self::CHART_TOP_ITEMS);
        $keptSet = array_flip($kept);
        $hasOther = count($ranked) > count($kept);

        $items = [];
        foreach ($kept as $id) {
            $items[] = ['key' => $id, 'name' => $names[$id]];
        }
        if ($hasOther) {
            $items[] = ['key' => self::OTHER_KEY, 'name' => 'Other'];
        }

        $buckets = array_keys($bucketSet);
        sort($buckets); // bucket formats are zero-padded, so lexicographic == chronological.

        $chartBuckets = [];
        foreach ($buckets as $bucket) {
            $values = [];
            $otherCents = 0;
            foreach ($net as $itemId => $byBucket) {
                $cents = $byBucket[$bucket] ?? 0;
                if ($cents === 0) {
                    continue; // omit empties; the frontend treats a missing key as 0.
                }
                if (isset($keptSet[$itemId])) {
                    $values[$itemId] = $this->pesos($cents);
                } else {
                    $otherCents += $cents;
                }
            }
            if ($otherCents !== 0) {
                $values[self::OTHER_KEY] = $this->pesos($otherCents);
            }
            // (string) the bucket: PHP coerces a purely-numeric bucket key
            // (e.g. a bare-year format like "2026") to an int, which would
            // serialise as a JSON number and break the frontend's string
            // handling. No current SUB_FORMAT is hyphen-free, but keep the cast
            // as a guard. Cast values so an empty bucket serialises as {} not [].
            $chartBuckets[] = ['bucket' => (string) $bucket, 'values' => (object) $values];
        }

        return ['items' => $items, 'buckets' => $chartBuckets];
    }

    /**
     * Per item + bucket, discount-apportioned sale revenue (line total ÷
     * transaction subtotal × transaction total), keyed by the sale's date, with
     * the item's current name. Mirrors {@see self::uncostedRevenueByBucket} but
     * grouped by item.
     *
     * @return Collection<int, object>
     */
    private function itemRevenueByBucket(string $format, Carbon $from, Carbon $to): Collection
    {
        $subtotals = DB::table('transaction_items')
            ->select('transaction_id')
            ->selectRaw('SUM(total) as subtotal')
            ->groupBy('transaction_id');

        return DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->join('variants as v', 'v.id', '=', 'ti.variant_id')
            ->join('items as it', 'it.id', '=', 'v.item_id')
            ->joinSub($subtotals, 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->where('sub.subtotal', '>', 0)
            ->whereBetween('t.created_at', [$from, $to])
            ->selectRaw('it.id as item_id, it.name as item_name, strftime(?, t.created_at) as bucket, SUM(ti.total * t.total / sub.subtotal) as revenue', [$format])
            ->groupBy('it.id', 'it.name', 'bucket')
            ->get();
    }

    /**
     * Per item + bucket, refund reversed by after-sales events of BOTH types
     * (returns and defects each reduce net), keyed by the event date, with the
     * item's current name.
     *
     * @return Collection<int, object>
     */
    private function itemRefundByBucket(string $format, Carbon $from, Carbon $to): Collection
    {
        return DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->join('variants as v', 'v.id', '=', 'sri.variant_id')
            ->join('items as it', 'it.id', '=', 'v.item_id')
            ->whereBetween('sr.created_at', [$from, $to])
            ->selectRaw('it.id as item_id, it.name as item_name, strftime(?, sr.created_at) as bucket, SUM(sri.refund_amount) as refund', [$format])
            ->groupBy('it.id', 'it.name', 'bucket')
            ->get();
    }

    /**
     * The best-selling items across the WHOLE selected range (not bucketed), for
     * the PDF's "Top selling items" table. Each item's net sales is its
     * discount-apportioned revenue (line total ÷ transaction subtotal ×
     * transaction total) minus its own returns + defects — the same recompute the
     * chart uses ({@see self::itemRevenueByBucket}, {@see self::itemRefundByBucket}),
     * only grouped by item instead of item + bucket. Ranked by net sales desc with
     * a name tie-break, capped at $limit.
     *
     * Only items that actually SOLD in the window are listed; an item touched only
     * by a refund (its sale fell in an earlier period) is omitted. Item cost is
     * never read here (net = revenue − refunds), so this is safe for every Admin+
     * viewer regardless of the Owner-only cost gate.
     *
     * @return list<array{name: string, quantity: int, net_sales: string}>
     */
    private function topItems(Carbon $dateFrom, Carbon $dateTo, int $limit = 10): array
    {
        $from = $dateFrom->copy();
        $to = $dateTo->copy();

        $subtotals = DB::table('transaction_items')
            ->select('transaction_id')
            ->selectRaw('SUM(total) as subtotal')
            ->groupBy('transaction_id');

        // Quantity sold + discount-apportioned revenue per item, keyed by item.
        $sales = DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->join('variants as v', 'v.id', '=', 'ti.variant_id')
            ->join('items as it', 'it.id', '=', 'v.item_id')
            ->joinSub($subtotals, 'sub', 'sub.transaction_id', '=', 'ti.transaction_id')
            ->where('sub.subtotal', '>', 0)
            ->whereBetween('t.created_at', [$from, $to])
            ->selectRaw('it.id as item_id, it.name as item_name, SUM(ti.quantity) as qty, SUM(ti.total * t.total / sub.subtotal) as revenue')
            ->groupBy('it.id', 'it.name')
            ->get();

        // Refunds (returns + defects both reduce net) per item, keyed by item.
        $refunds = DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->join('variants as v', 'v.id', '=', 'sri.variant_id')
            ->whereBetween('sr.created_at', [$from, $to])
            ->selectRaw('v.item_id as item_id, SUM(sri.refund_amount) as refund')
            ->groupBy('v.item_id')
            ->pluck('refund', 'item_id');

        $names = [];
        $qty = [];
        $net = []; // item_id => net sales centavos
        foreach ($sales as $row) {
            $names[$row->item_id] = $row->item_name;
            $qty[$row->item_id] = (int) $row->qty;
            $net[$row->item_id] = $this->centavos($row->revenue);
        }
        foreach ($refunds as $itemId => $refund) {
            if (isset($net[$itemId])) {
                $net[$itemId] -= $this->centavos($refund);
            }
        }

        // Rank by net sales desc, name asc as a stable tie-break (mirrors buildChart).
        $ids = array_keys($net);
        usort($ids, fn ($a, $b) => ($net[$b] <=> $net[$a]) ?: strcmp($names[$a], $names[$b]));

        return array_map(fn ($id) => [
            'name' => $names[$id],
            'quantity' => $qty[$id],
            'net_sales' => $this->pesos($net[$id]),
        ], array_slice($ids, 0, $limit));
    }

    /**
     * Sum the per-bucket rows into period totals for the summary cards. Money is
     * re-summed through centavos for a cent-exact grand total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function sumTotals(array $rows, bool $canViewCost): array
    {
        $totals = [
            'transactions' => 0,
            'gross_sales' => 0,
            'returns' => 0,
            'defects' => 0,
            'net_sales' => 0,
            'cogs' => 0,
            'profit' => 0,
            'uncosted_sales' => 0,
        ];

        foreach ($rows as $row) {
            $totals['transactions'] += $row['transactions'];
            $totals['gross_sales'] += $this->centavos($row['gross_sales']);
            $totals['returns'] += $this->centavos($row['returns']);
            $totals['defects'] += $this->centavos($row['defects']);
            $totals['net_sales'] += $this->centavos($row['net_sales']);
            if ($canViewCost) {
                $totals['cogs'] += $this->centavos($row['cogs']);
                $totals['profit'] += $this->centavos($row['profit']);
                $totals['uncosted_sales'] += $this->centavos($row['uncosted_sales']);
            }
        }

        $result = [
            'transactions' => $totals['transactions'],
            'gross_sales' => $this->pesos($totals['gross_sales']),
            'returns' => $this->pesos($totals['returns']),
            'defects' => $this->pesos($totals['defects']),
            'net_sales' => $this->pesos($totals['net_sales']),
        ];

        if ($canViewCost) {
            $result['cogs'] = $this->pesos($totals['cogs']);
            $result['profit'] = $this->pesos($totals['profit']);
            $result['uncosted_sales'] = $this->pesos($totals['uncosted_sales']);
        }

        return $result;
    }

    /**
     * Snap an anchor date to the [start, end] bounds of the single selected
     * period. The anchor defaults to "now" when no (parseable) date is given.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(string $period, ?Carbon $anchor): array
    {
        $a = ($anchor ?? Carbon::now())->copy();

        return match ($period) {
            'weekly' => [$a->copy()->startOfWeek(), $a->copy()->endOfWeek()],
            'monthly' => [$a->copy()->startOfMonth(), $a->copy()->endOfMonth()],
            'yearly' => [$a->copy()->startOfYear(), $a->copy()->endOfYear()],
            default => [$a->copy()->startOfDay(), $a->copy()->endOfDay()],
        };
    }

    /**
     * The `filters` payload the frontend needs to render its selector: the
     * active period, the window-start anchor (what the date picker shows), the
     * prev/next anchors for the ‹ › steppers, and a human label for the period.
     *
     * @return array<string, string>
     */
    private function filters(string $period, Carbon $from): array
    {
        $step = fn (Carbon $d, int $sign): Carbon => match ($period) {
            'weekly' => $d->copy()->addWeeks($sign),
            'monthly' => $d->copy()->addMonths($sign),
            'yearly' => $d->copy()->addYears($sign),
            default => $d->copy()->addDays($sign),
        };

        return [
            'period' => $period,
            'date' => $from->toDateString(),
            'prev' => $step($from, -1)->toDateString(),
            'next' => $step($from, 1)->toDateString(),
            'label' => $this->periodLabel($period, $from),
        ];
    }

    /** A human-readable label for the selected period, e.g. "July 2026". */
    private function periodLabel(string $period, Carbon $from): string
    {
        return match ($period) {
            'weekly' => $from->format('M j').' – '.$from->copy()->endOfWeek()->format('M j, Y'),
            'monthly' => $from->format('F Y'),
            'yearly' => $from->format('Y'),
            default => $from->format('l, M j, Y'),
        };
    }

    /**
     * Human label for one sub-bucket key (the breakdown table's first column in
     * the PDF), mirroring the frontend's formatBucket. Daily buckets are
     * '%Y-%m-%d %H' (hour), weekly/monthly '%Y-%m-%d' (day), yearly '%Y-%m'
     * (month).
     */
    private function formatBucketLabel(string $period, string $bucket): string
    {
        return match ($period) {
            'daily' => Carbon::createFromFormat('Y-m-d H', $bucket)->format('g:00 A'),
            'yearly' => Carbon::createFromFormat('Y-m', $bucket)->format('F Y'),
            default => Carbon::createFromFormat('Y-m-d', $bucket)->format('D, M j'),
        };
    }

    /**
     * Constrain the granularity to a known period, defaulting to daily.
     */
    private function normalizePeriod(mixed $value): string
    {
        $value = (string) $value;

        return array_key_exists($value, self::SUB_FORMATS) ? $value : 'daily';
    }

    /**
     * Parse a date filter param, discarding anything unparseable, else null.
     */
    private function normalizeDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Money (peso decimal string/number) → integer centavos, drift-free. */
    private function centavos(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    /** Integer centavos → a "0.00" peso string. */
    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
