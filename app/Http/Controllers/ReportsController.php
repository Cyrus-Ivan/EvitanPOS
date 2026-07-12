<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business reporting (Phase 12). Read-only. Daily and Monthly sales reports that
 * live on one page, switched by an in-page `period` toggle.
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
    /** Reporting granularities and their SQLite strftime bucket formats. */
    private const PERIODS = [
        'daily' => '%Y-%m-%d',
        'monthly' => '%Y-%m',
    ];

    public function index(Request $request): Response
    {
        $period = $this->normalizePeriod($request->query('period'));
        [$dateFrom, $dateTo] = $this->resolveRange($period, $request);
        $canViewCost = Gate::allows('view-item-cost');

        $rows = $this->aggregate($period, $dateFrom, $dateTo, $canViewCost);

        return Inertia::render('reports', [
            'period' => $period,
            'rows' => $rows,
            'totals' => $this->sumTotals($rows, $canViewCost),
            'canViewCost' => $canViewCost,
            'filters' => [
                'period' => $period,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
        ]);
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
        $format = self::PERIODS[$period];
        $from = $dateFrom->copy()->startOfDay();
        $to = $dateTo->copy()->endOfDay();

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
     * Default date range per granularity: current month for daily, current year
     * for monthly. An explicit, parseable date_from / date_to overrides it.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(string $period, Request $request): array
    {
        $now = Carbon::now();
        $default = $period === 'monthly'
            ? [$now->copy()->startOfYear(), $now->copy()->endOfYear()]
            : [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];

        $from = $this->normalizeDate($request->query('date_from')) ?? $default[0];
        $to = $this->normalizeDate($request->query('date_to')) ?? $default[1];

        // Guard against an inverted range.
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Constrain the granularity to a known period, defaulting to daily.
     */
    private function normalizePeriod(mixed $value): string
    {
        $value = (string) $value;

        return array_key_exists($value, self::PERIODS) ? $value : 'daily';
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
