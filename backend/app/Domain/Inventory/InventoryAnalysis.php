<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory analysis over the issue history of a window: ABC (consumption value), FSN (movement recency), VED (criticality, set on the
 * product), HML (unit cost), SDE (supply lead time), XYZ (demand variability) and EOQ. A run is a stored snapshot, so results are
 * reproducible and comparable; combined matrices cross any two classifications. Thresholds are per tenant and configurable.
 */
final class InventoryAnalysis
{
    public const DIMENSIONS = ['abc', 'fsn', 'ved', 'hml', 'sde', 'xyz'];

    public const DEFAULTS = [
        'window_days' => 365,
        'abc_a_pct' => 80, 'abc_b_pct' => 95, // cumulative share of consumption value
        'fsn_fast_days' => 30, 'fsn_slow_days' => 90, // days since the last issue
        'hml_high_cost' => 500000, 'hml_low_cost' => 50000, // rupiah per base unit
        'sde_scarce_days' => 30, 'sde_difficult_days' => 8, // supply lead time
        'xyz_x_cv' => 0.5, 'xyz_y_cv' => 1.0, // coefficient of variation of monthly demand
        'eoq_ordering_cost' => 100000, 'eoq_holding_pct' => 20, // rupiah per order; percent of unit cost per year
    ];

    private const RULES = [
        'window_days' => [30, 730], 'abc_a_pct' => [1, 99], 'abc_b_pct' => [2, 100], 'fsn_fast_days' => [1, 365], 'fsn_slow_days' => [2, 730],
        'hml_high_cost' => [1, 1000000000], 'hml_low_cost' => [0, 1000000000], 'sde_scarce_days' => [2, 365], 'sde_difficult_days' => [1, 364],
        'xyz_x_cv' => [0.01, 10], 'xyz_y_cv' => [0.02, 20], 'eoq_ordering_cost' => [1, 1000000000], 'eoq_holding_pct' => [1, 100],
    ];

    public function __construct(private readonly TenantStore $store) {}

    public function config(): array
    {
        $row = DB::table('tenant_settings')->where('tenant_id', $this->store->tenantId)->where('key', 'analysis')->value('value');

        return array_merge(self::DEFAULTS, $row ? json_decode($row, true) : []);
    }

    /** @param array<string, int|float> $values */
    public function saveConfig(array $values): array
    {
        $config = array_merge($this->config(), array_intersect_key($values, self::RULES));
        foreach (self::RULES as $key => [$min, $max]) {
            abort_unless(is_numeric($config[$key]) && $config[$key] >= $min && $config[$key] <= $max, 422, "Nilai {$key} harus antara {$min} dan {$max}.");
        }
        abort_unless($config['abc_a_pct'] < $config['abc_b_pct'], 422, 'Batas A harus lebih kecil dari batas B.');
        abort_unless($config['fsn_fast_days'] < $config['fsn_slow_days'], 422, 'Batas fast harus lebih kecil dari batas slow.');
        abort_unless($config['hml_low_cost'] < $config['hml_high_cost'], 422, 'Batas low harus lebih kecil dari batas high.');
        abort_unless($config['sde_difficult_days'] < $config['sde_scarce_days'], 422, 'Batas difficult harus lebih kecil dari batas scarce.');
        abort_unless($config['xyz_x_cv'] < $config['xyz_y_cv'], 422, 'Batas X harus lebih kecil dari batas Y.');

        return DB::transaction(function () use ($config) {
            $before = $this->config();
            DB::table('tenant_settings')->upsert(['tenant_id' => $this->store->tenantId, 'key' => 'analysis', 'value' => json_encode($config), 'updated_at' => now()], ['tenant_id', 'key'], ['value', 'updated_at']);
            $this->store->event('analysis.configured', $this->store->tenantId, ['changed' => array_keys(array_diff_assoc($config, $before))]);

            return $config;
        }, 3);
    }

    /** Runs the analysis now and stores the snapshot. @return array{id: string, products: int} */
    public function run(): array
    {
        $c = $this->config();
        $now = CarbonImmutable::now();
        $from = $now->subDays((int) $c['window_days']);
        $products = $this->store->query('products')->where('active', true)->orderBy('code')->get();
        abort_if($products->isEmpty(), 422, 'Belum ada produk aktif untuk dianalisis.');

        $issues = DB::table('stock_movements as m')->where('m.tenant_id', $this->store->tenantId)->where('m.type', 'ISSUE')->where('m.created_at', '>=', $from)
            ->join('stock_units as u', fn ($j) => $j->on('u.id', '=', 'm.stock_unit_id')->on('u.tenant_id', '=', 'm.tenant_id'))
            ->get(['u.product_id', 'm.quantity', 'm.created_at'])->groupBy('product_id');

        $rows = [];
        foreach ($products as $p) {
            $list = $issues[$p->id] ?? collect();
            $qty = (int) abs($list->sum('quantity'));
            $last = $list->max('created_at');
            $monthly = array_fill(0, 12, 0);
            foreach ($list as $m) { // demand per 30-day bucket, newest bucket first, over at most the last 12 buckets
                $bucket = (int) floor(CarbonImmutable::parse($m->created_at)->diffInDays($now) / 30);
                if ($bucket < 12) {
                    $monthly[$bucket] += abs($m->quantity);
                }
            }
            $rows[$p->id] = [
                'product' => $p, 'qty' => $qty, 'value' => $qty * (int) ($p->unit_cost ?? 0), 'movements' => $list->count(),
                'days' => $last ? (int) CarbonImmutable::parse($last)->diffInDays($now) : null, 'monthly' => $monthly,
            ];
        }
        $total = array_sum(array_column($rows, 'value'));
        uasort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        $cum = 0.0;
        foreach ($rows as $id => &$r) {
            $before = $total > 0 ? 100 * $cum / $total : 100;
            $r['abc'] = $r['value'] <= 0 ? 'C' : ($before < $c['abc_a_pct'] ? 'A' : ($before < $c['abc_b_pct'] ? 'B' : 'C'));
            $cum += $r['value'];
        }
        unset($r);

        $runId = (string) Str::uuid();

        return DB::transaction(function () use ($rows, $c, $runId, $now) {
            DB::table('analysis_runs')->insert(['id' => $runId, 'tenant_id' => $this->store->tenantId, 'run_at' => $now, 'window_days' => $c['window_days'], 'config' => json_encode($c), 'products' => count($rows), 'created_by' => $this->store->actorId]);
            $insert = [];
            foreach ($rows as $id => $r) {
                $p = $r['product'];
                $mean = array_sum($r['monthly']) / 12;
                $cv = $mean > 0 ? sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $r['monthly'])) / 12) / $mean : null;
                $holding = ($p->unit_cost ?? 0) * $c['eoq_holding_pct'] / 100;
                $annual = $r['qty'] * 365 / $c['window_days'];
                $insert[] = [
                    'run_id' => $runId, 'tenant_id' => $this->store->tenantId, 'product_id' => $id, 'consumption_qty' => $r['qty'], 'consumption_value' => $r['value'],
                    'movements' => $r['movements'], 'days_since_last_issue' => $r['days'], 'abc' => $r['abc'],
                    'fsn' => $r['days'] === null || $r['days'] > $c['fsn_slow_days'] ? 'N' : ($r['days'] <= $c['fsn_fast_days'] ? 'F' : 'S'),
                    'ved' => $p->criticality,
                    'hml' => ($p->unit_cost ?? 0) >= $c['hml_high_cost'] ? 'H' : (($p->unit_cost ?? 0) < $c['hml_low_cost'] ? 'L' : 'M'),
                    'sde' => ($p->lead_time_days ?? 0) > $c['sde_scarce_days'] ? 'S' : (($p->lead_time_days ?? 0) >= $c['sde_difficult_days'] ? 'D' : 'E'),
                    'xyz' => $cv === null ? 'Z' : ($cv <= $c['xyz_x_cv'] ? 'X' : ($cv <= $c['xyz_y_cv'] ? 'Y' : 'Z')),
                    'eoq' => $holding > 0 && $annual > 0 ? (int) round(sqrt(2 * $annual * $c['eoq_ordering_cost'] / $holding)) : null,
                ];
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('analysis_results')->insert($chunk);
            }
            $this->store->event('analysis.run', $runId, ['products' => count($insert)]);

            return ['id' => $runId, 'products' => count($insert)];
        }, 3);
    }

    public function latestRunId(): ?string
    {
        return $this->store->query('analysis_runs')->orderByDesc('run_at')->value('id');
    }

    public function runs(): array
    {
        return $this->store->query('analysis_runs')->orderByDesc('run_at')->limit(20)->get(['id', 'run_at', 'window_days', 'products'])->all();
    }

    /** Results of one run (default: the latest), optionally filtered on a classification, e.g. abc=A. */
    public function results(?string $runId, array $filters): array
    {
        $runId ??= $this->latestRunId();
        abort_if($runId === null, 404, 'Belum ada hasil analisis; jalankan analisis terlebih dahulu.');
        $this->store->find('analysis_runs', $runId);
        $q = DB::table('analysis_results as r')->where('r.tenant_id', $this->store->tenantId)->where('r.run_id', $runId)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'r.product_id')->on('p.tenant_id', '=', 'r.tenant_id'));
        foreach (self::DIMENSIONS as $d) {
            if (! empty($filters[$d])) {
                $q->where("r.{$d}", strtoupper((string) $filters[$d]));
            }
        }

        return ['run_id' => $runId, 'data' => $q->orderByDesc('r.consumption_value')->orderBy('p.code')->limit(1000)
            ->get(['p.id as product_id', 'p.code', 'p.name', 'r.consumption_qty', 'r.consumption_value', 'r.movements', 'r.days_since_last_issue', 'r.abc', 'r.fsn', 'r.ved', 'r.hml', 'r.sde', 'r.xyz', 'r.eoq'])->all()];
    }

    /** A combined matrix of two classifications: the count (and first product codes) of every cell, e.g. ABC x XYZ. */
    public function matrix(?string $runId, string $rowDim, string $colDim): array
    {
        abort_unless(in_array($rowDim, self::DIMENSIONS, true) && in_array($colDim, self::DIMENSIONS, true) && $rowDim !== $colDim, 422, 'Pilih dua klasifikasi yang berbeda.');
        $res = $this->results($runId, []);
        $cells = [];
        foreach ($res['data'] as $r) {
            $key = $r->{$rowDim}.'|'.$r->{$colDim};
            $cells[$key]['row'] = $r->{$rowDim};
            $cells[$key]['col'] = $r->{$colDim};
            $cells[$key]['count'] = ($cells[$key]['count'] ?? 0) + 1;
            if (count($cells[$key]['products'] ?? []) < 10) {
                $cells[$key]['products'][] = $r->code;
            }
        }
        ksort($cells);

        return ['run_id' => $res['run_id'], 'row' => $rowDim, 'col' => $colDim, 'cells' => array_values($cells)];
    }
}
