<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private string $wh;

    private string $uom;

    /** @var array<string, string> bin code => id */
    private array $bins = [];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
        $this->manager = auth()->user();
        $this->wh = $this->postJson('/api/v1/master/warehouses', ['code' => 'WH1', 'name' => 'G1', 'granularity' => 'BIN'])->json('data.id');
        $this->uom = $this->postJson('/api/v1/master/uoms', ['code' => 'PCS', 'name' => 'Pieces'])->json('data.id');
        $z = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['code' => 'Z1', 'name' => 'Z'])->json('data.id');
        $a = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $z, 'code' => 'Z1-A1', 'name' => 'A'])->json('data.id');
        $r = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $a, 'code' => 'Z1-A1-R1', 'name' => 'R'])->json('data.id');
        $s = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $r, 'code' => 'Z1-A1-R1-S1', 'name' => 'S'])->json('data.id');
        $this->postJson("/api/v1/warehouses/{$this->wh}/locations/generate", ['parent_id' => $s, 'prefix' => 'B', 'count' => 2])->assertCreated();
        $this->bins = DB::table('storage_nodes')->where('level', 'BIN')->pluck('id', 'code')->all();
        DB::table('storage_nodes')->where('id', $this->bins['Z1-A1-R1-S1-B01'])->update(['capacity_base_units' => 200]);
    }

    private function product(string $code, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert(array_merge(['id' => $id, 'tenant_id' => $this->tenant, 'code' => $code, 'name' => $code, 'base_uom_id' => $this->uom, 'rotation_policy' => 'FIFO', 'track_lot' => false,
            'track_expiry' => false, 'temperature_class' => 'AMBIENT', 'hazmat' => false, 'criticality' => 'D', 'created_at' => now(), 'updated_at' => now()], $extra));

        return $id;
    }

    private function unit(string $product, int $qty, string $bin = 'Z1-A1-R1-S1-B01', string $status = 'AVAILABLE'): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_units')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'lpn' => 'LPN-'.Str::upper(Str::random(8)), 'warehouse_id' => $this->wh, 'product_id' => $product, 'quantity' => $qty,
            'location_id' => $bin ? $this->bins[$bin] : null, 'status' => $status, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function issue(string $unit, int $qty, int $daysAgo): void
    {
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'stock_unit_id' => $unit, 'type' => 'ISSUE', 'quantity' => -$qty,
            'actor_id' => $this->manager->id, 'created_at' => now()->subDays($daysAgo)]);
    }

    public function test_stock_map_rolls_up_and_drills_down(): void
    {
        $p = $this->product('P1');
        $this->unit($p, 150);
        $this->unit($p, 30, 'Z1-A1-R1-S1-B02');
        $this->unit($p, 99, 'Z1-A1-R1-S1-B01', 'QUARANTINE');
        $this->unit($p, 10, '', 'PENDING_PUTAWAY');

        $map = collect($this->getJson("/api/v1/inventory/map/{$this->wh}")->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(279, $map['Z1']['quantity']);
        $this->assertSame(249, $map['Z1-A1-R1-S1-B01']['quantity']);
        $this->assertSame(100.0, (float) $map['Z1-A1-R1-S1-B01']['utilization']); // capped at 100 %
        $this->assertSame(30, $map['Z1-A1-R1-S1-B02']['quantity']);
        $this->assertNull($map['Z1']['utilization']);

        $units = $this->getJson("/api/v1/inventory/locations/{$this->bins['Z1-A1-R1-S1-B01']}/units")->assertOk()->json('data');
        $this->assertCount(2, $units);
        $this->assertCount(3, $this->getJson('/api/v1/inventory/locations/'.DB::table('storage_nodes')->where('code', 'Z1')->value('id').'/units')->json('data'));

        $summary = $this->getJson('/api/v1/inventory/summary')->assertOk()->json('data.0');
        $this->assertSame([180, 0, 99, 10], [$summary['available'], $summary['locked'], $summary['quarantine'], $summary['pending_putaway']]);
    }

    public function test_locking_stock_needs_a_reason_and_moves_it_out_of_available(): void
    {
        $unit = $this->unit($this->product('P1'), 50);
        $this->postJson("/api/v1/stock-units/{$unit}/lock", ['locked' => true, 'reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/stock-units/{$unit}/lock", ['locked' => true, 'reason' => 'Audit internal'])->assertOk()->assertJsonPath('data.locked', true);
        $this->assertSame([0, 50], [$this->getJson('/api/v1/inventory/summary')->json('data.0.available'), $this->getJson('/api/v1/inventory/summary')->json('data.0.locked')]);
        $this->postJson("/api/v1/stock-units/{$unit}/lock", ['locked' => false, 'reason' => 'Audit selesai'])->assertOk()->assertJsonPath('data.locked', false);
        $this->assertSame(['LOCK', 'UNLOCK'], DB::table('stock_movements')->where('stock_unit_id', $unit)->pluck('type')->sort()->values()->all());
        // Quarantined stock is not lockable (it is already held by quality).
        $q = $this->unit($this->product('P2'), 5, 'Z1-A1-R1-S1-B02', 'QUARANTINE');
        $this->postJson("/api/v1/stock-units/{$q}/lock", ['locked' => true, 'reason' => 'Audit internal'])->assertUnprocessable();
        // Locking a whole rack locks everything available below it.
        $this->unit($this->product('P3'), 7, 'Z1-A1-R1-S1-B02');
        $rack = DB::table('storage_nodes')->where('code', 'Z1-A1-R1')->value('id');
        $this->postJson("/api/v1/locations/{$rack}/lock-stock", ['reason' => 'Rak diaudit'])->assertOk()->assertJsonPath('data.locked', 2);
    }

    public function test_cycle_count_needs_reasons_and_a_second_person_to_approve(): void
    {
        $p = $this->product('P1');
        $a = $this->unit($p, 100);
        $b = $this->unit($p, 40, 'Z1-A1-R1-S1-B02');
        $take = $this->postJson('/api/v1/stocktakes', ['warehouse_id' => $this->wh])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^CC-\d{6}\d{5}$/', $take['number']);
        $this->assertCount(2, $take['lines']);
        // Units already in an open count cannot be counted twice at once.
        $this->postJson('/api/v1/stocktakes', ['warehouse_id' => $this->wh])->assertUnprocessable();

        $lines = collect($take['lines'])->keyBy('expected_qty');
        $this->postJson("/api/v1/stocktakes/{$take['id']}/submit")->assertUnprocessable(); // not counted yet
        $this->postJson("/api/v1/stocktake-lines/{$lines[100]['id']}/count", ['counted_qty' => 95])->assertUnprocessable(); // variance needs a reason
        $this->postJson("/api/v1/stocktake-lines/{$lines[100]['id']}/count", ['counted_qty' => 95, 'reason' => 'Lima karton rusak dibuang'])->assertOk();
        $this->postJson("/api/v1/stocktake-lines/{$lines[40]['id']}/count", ['counted_qty' => 40])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$take['id']}/submit")->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
        // The person who counted and submitted cannot approve; nothing changed meanwhile.
        $this->postJson("/api/v1/stocktakes/{$take['id']}/approve")->assertForbidden();
        $this->assertSame(100, (int) DB::table('stock_units')->where('id', $a)->value('quantity'));

        $second = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $second->id, 'role' => 'warehouse_manager', 'active' => true]);
        $this->actingAs($second)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/stocktakes/{$take['id']}/reject", ['reason' => 'Hitung ulang rak B01'])->assertOk()->assertJsonPath('data.status', 'COUNTING');
        $this->actingAs($this->manager)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/stocktakes/{$take['id']}/submit")->assertOk();
        $this->actingAs($second)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/stocktakes/{$take['id']}/approve")->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertSame(95, (int) DB::table('stock_units')->where('id', $a)->value('quantity'));
        $this->assertSame(40, (int) DB::table('stock_units')->where('id', $b)->value('quantity'));
        $adj = DB::table('stock_movements')->where('type', 'ADJUST')->get();
        $this->assertCount(1, $adj);
        $this->assertSame(-5, (int) $adj[0]->quantity);
        $this->postJson("/api/v1/stocktakes/{$take['id']}/approve")->assertUnprocessable();
    }

    public function test_a_counted_zero_depletes_the_unit(): void
    {
        $u = $this->unit($this->product('P1'), 12);
        $take = $this->postJson('/api/v1/stocktakes', ['warehouse_id' => $this->wh, 'location_id' => $this->bins['Z1-A1-R1-S1-B01']])->assertCreated()->json('data');
        $this->postJson("/api/v1/stocktake-lines/{$take['lines'][0]['id']}/count", ['counted_qty' => 0, 'reason' => 'Barang tidak ditemukan'])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$take['id']}/submit")->assertOk();
        $second = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $second->id, 'role' => 'warehouse_manager', 'active' => true]);
        $this->actingAs($second)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/stocktakes/{$take['id']}/approve")->assertOk();
        $this->assertSame(['DEPLETED', 0], [DB::table('stock_units')->where('id', $u)->value('status'), (int) DB::table('stock_units')->where('id', $u)->value('quantity')]);
        $this->assertSame([], $this->getJson("/api/v1/inventory/locations/{$this->bins['Z1-A1-R1-S1-B01']}/units")->json('data'));
    }

    public function test_analysis_classifies_products_and_builds_matrices(): void
    {
        $fast = $this->product('FAST', ['unit_cost' => 600000, 'criticality' => 'V', 'lead_time_days' => 45]);
        $mid = $this->product('MID', ['unit_cost' => 100000, 'criticality' => 'E', 'lead_time_days' => 10]);
        $slow = $this->product('SLOW', ['unit_cost' => 1000, 'lead_time_days' => 2]);
        $none = $this->product('NONE');
        $uf = $this->unit($fast, 1000);
        $um = $this->unit($mid, 1000);
        $us = $this->unit($slow, 1000);
        // FAST: steady monthly issues of 10 (value 100 x 1 000 000 ... dominates); MID: occasional; SLOW: one old small issue.
        foreach (range(0, 11) as $m) {
            $this->issue($uf, 10, $m * 30 + 5);
        }
        $this->issue($um, 50, 60);
        $this->issue($um, 30, 200);
        $this->issue($us, 1, 150);

        $this->getJson('/api/v1/analysis/results')->assertNotFound();
        $this->postJson('/api/v1/analysis/run')->assertCreated()->assertJsonPath('data.products', 4);
        $res = collect($this->getJson('/api/v1/analysis/results')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['A', 'F', 'V', 'H', 'S', 'X'], [$res['FAST']['abc'], $res['FAST']['fsn'], $res['FAST']['ved'], $res['FAST']['hml'], $res['FAST']['sde'], $res['FAST']['xyz']]);
        $this->assertSame(120, (int) $res['FAST']['consumption_qty']);
        $this->assertSame(72000000, (int) $res['FAST']['consumption_value']);
        $this->assertSame(['B', 'S', 'E', 'M', 'D', 'Z'], [$res['MID']['abc'], $res['MID']['fsn'], $res['MID']['ved'], $res['MID']['hml'], $res['MID']['sde'], $res['MID']['xyz']]);
        $this->assertSame(['C', 'N', 'D', 'L', 'E'], [$res['SLOW']['abc'], $res['SLOW']['fsn'], $res['SLOW']['ved'], $res['SLOW']['hml'], $res['SLOW']['sde']]);
        $this->assertSame(['C', 'N', 'Z', null], [$res['NONE']['abc'], $res['NONE']['fsn'], $res['NONE']['xyz'], $res['NONE']['eoq']]);
        // EOQ = sqrt(2 * D * S / H): D = 120 per 365 days, S = 100 000, H = 20 % of 600 000.
        $this->assertSame((int) round(sqrt(2 * 120 * 100000 / 120000)), (int) $res['FAST']['eoq']);

        $matrix = $this->getJson('/api/v1/analysis/matrix?row=abc&col=fsn')->assertOk()->json('data');
        $cells = collect($matrix['cells'])->keyBy(fn ($c) => $c['row'].$c['col']);
        $this->assertSame(['FAST'], $cells['AF']['products']);
        $this->assertSame(2, $cells['CN']['count']); // SLOW and NONE share C/N
        $this->getJson('/api/v1/analysis/matrix?row=abc&col=abc')->assertUnprocessable();
        $this->assertCount(1, $this->getJson('/api/v1/analysis/results?abc=A')->json('data'));
    }

    public function test_analysis_configuration_is_validated_audited_and_permissioned(): void
    {
        $this->putJson('/api/v1/analysis/config', ['abc_a_pct' => 90, 'abc_b_pct' => 85])->assertUnprocessable();
        $this->putJson('/api/v1/analysis/config', ['fsn_fast_days' => 0])->assertUnprocessable();
        $this->putJson('/api/v1/analysis/config', ['abc_a_pct' => 70, 'abc_b_pct' => 90])->assertOk()->assertJsonPath('data.abc_a_pct', 70);
        $this->getJson('/api/v1/analysis/config')->assertJsonPath('data.abc_b_pct', 90);
        $this->assertDatabaseHas('audit_events', ['action' => 'analysis.configured']);

        $auditor = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $auditor->id, 'role' => 'quality_auditor', 'active' => true]);
        $this->actingAs($auditor)->withSession(['tenant_id' => $this->tenant]);
        $this->getJson('/api/v1/analysis/config')->assertOk(); // may view
        $this->putJson('/api/v1/analysis/config', ['abc_a_pct' => 60])->assertForbidden();
        $this->postJson('/api/v1/analysis/run')->assertForbidden();
        $this->postJson('/api/v1/stocktakes', ['warehouse_id' => $this->wh])->assertForbidden();
    }
}
