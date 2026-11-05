<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class OutboundTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private string $wh;

    private string $uom;

    private string $customer;

    /** @var array<string, string> */
    private array $bins = [];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
        $this->manager = auth()->user();
        $this->wh = $this->postJson('/api/v1/master/warehouses', ['code' => 'WH1', 'name' => 'G1', 'granularity' => 'BIN'])->json('data.id');
        $this->uom = $this->postJson('/api/v1/master/uoms', ['code' => 'PCS', 'name' => 'Pieces'])->json('data.id');
        $this->customer = $this->postJson('/api/v1/master/partners', ['code' => 'CUS1', 'name' => 'PT Pelanggan', 'type' => 'CUSTOMER'])->json('data.id');
        $z = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['code' => 'Z1', 'name' => 'Z'])->json('data.id');
        $a = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $z, 'code' => 'Z1-A1', 'name' => 'A'])->json('data.id');
        $r = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $a, 'code' => 'Z1-A1-R1', 'name' => 'R'])->json('data.id');
        $s = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $r, 'code' => 'Z1-A1-R1-S1', 'name' => 'S'])->json('data.id');
        $this->postJson("/api/v1/warehouses/{$this->wh}/locations/generate", ['parent_id' => $s, 'prefix' => 'B', 'count' => 3])->assertCreated();
        $this->bins = DB::table('storage_nodes')->where('level', 'BIN')->pluck('id', 'code')->all();
    }

    private function product(string $code, string $policy = 'FIFO'): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'code' => $code, 'name' => $code, 'base_uom_id' => $this->uom, 'rotation_policy' => $policy, 'track_lot' => false,
            'track_expiry' => $policy === 'FEFO', 'temperature_class' => 'AMBIENT', 'hazmat' => false, 'criticality' => 'D', 'weight_g' => 500, 'barcode' => 'BC-'.$code, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function unit(string $product, int $qty, string $bin, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_units')->insert(array_merge(['id' => $id, 'tenant_id' => $this->tenant, 'lpn' => 'LPN-'.Str::upper(Str::random(8)), 'warehouse_id' => $this->wh, 'product_id' => $product, 'quantity' => $qty,
            'location_id' => $this->bins[$bin], 'status' => 'AVAILABLE', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()], $extra));

        return $id;
    }

    private function order(array $lines): array
    {
        $o = $this->postJson('/api/v1/outbound-orders', ['customer_id' => $this->customer, 'warehouse_id' => $this->wh, 'required_date' => now()->addDay()->toDateString(), 'reference' => 'PO-C1',
            'ship_to' => 'Jl. Pelanggan 1, Jakarta', 'lines' => $lines])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^SO-\d{6}\d{5}$/', $o['number']);
        $this->postJson("/api/v1/outbound-orders/{$o['id']}/release")->assertOk();

        return $o;
    }

    private function allocate(string $id): array
    {
        return $this->postJson("/api/v1/outbound-orders/{$id}/allocate")->assertOk()->json('data');
    }

    public function test_allocation_follows_the_rotation_policy_and_skips_unusable_stock(): void
    {
        $fefo = $this->product('MILK', 'FEFO');
        $late = $this->unit($fefo, 50, 'Z1-A1-R1-S1-B01', ['expiry_date' => now()->addDays(300)->toDateString(), 'received_at' => now()->subDays(5)]);
        $soon = $this->unit($fefo, 50, 'Z1-A1-R1-S1-B02', ['expiry_date' => now()->addDays(40)->toDateString(), 'received_at' => now()->subDay()]);
        $locked = $this->unit($fefo, 50, 'Z1-A1-R1-S1-B03', ['expiry_date' => now()->addDays(10)->toDateString(), 'locked' => true]);
        $order = $this->order([['product_id' => $fefo, 'ordered_qty' => 70]]);
        $res = $this->allocate($order['id']);
        $this->assertSame('ALLOCATED', $res['status']);
        $this->assertSame(70, $res['lines'][0]['allocated_qty']);
        // Earliest expiry first (the locked unit is never touched), then the next one for the rest.
        $this->assertSame([50, 20], [(int) DB::table('allocations')->where('stock_unit_id', $soon)->value('quantity'), (int) DB::table('allocations')->where('stock_unit_id', $late)->value('quantity')]);
        $this->assertSame(0, DB::table('allocations')->where('stock_unit_id', $locked)->count());
        $this->assertSame([50, 20, 0], array_map(fn ($u) => (int) DB::table('stock_units')->where('id', $u)->value('reserved_qty'), [$soon, $late, $locked]));
        $this->assertSame(30, (int) $this->getJson('/api/v1/inventory/summary')->json('data.0.available')); // 150 stored, 50 locked, 70 reserved

        // A second order only gets what is left (short allocation stays visible); cancelling gives the reservations back.
        $second = $this->order([['product_id' => $fefo, 'ordered_qty' => 100]]);
        $r2 = $this->allocate($second['id']);
        $this->assertSame(30, (int) $r2['lines'][0]['allocated_qty']);
        $this->postJson("/api/v1/outbound-orders/{$second['id']}/cancel", ['reason' => 'Pelanggan membatalkan'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame([50, 20], array_map(fn ($u) => (int) DB::table('stock_units')->where('id', $u)->value('reserved_qty'), [$soon, $late]));

        // No stock at all: nothing to allocate.
        $other = $this->product('NOSTOCK');
        $empty = $this->order([['product_id' => $other, 'ordered_qty' => 1]]);
        $this->postJson("/api/v1/outbound-orders/{$empty['id']}/allocate")->assertUnprocessable();
        // Blocked locations are skipped.
        $fifo = $this->product('RICE');
        $this->unit($fifo, 10, 'Z1-A1-R1-S1-B01');
        $this->postJson('/api/v1/locations/'.DB::table('storage_nodes')->where('code', 'Z1-A1')->value('id').'/block', ['blocked' => true, 'reason' => 'Lorong diperbaiki'])->assertOk();
        $blocked = $this->order([['product_id' => $fifo, 'ordered_qty' => 5]]);
        $this->postJson("/api/v1/outbound-orders/{$blocked['id']}/allocate")->assertUnprocessable();
    }

    public function test_fifo_and_lifo_pick_the_oldest_and_newest_receipt(): void
    {
        $fifo = $this->product('RICE', 'FIFO');
        $old = $this->unit($fifo, 10, 'Z1-A1-R1-S1-B01', ['received_at' => now()->subDays(10)]);
        $new = $this->unit($fifo, 10, 'Z1-A1-R1-S1-B02', ['received_at' => now()->subDay()]);
        $this->allocate($this->order([['product_id' => $fifo, 'ordered_qty' => 10]])['id']);
        $this->assertSame([10, 0], [(int) DB::table('stock_units')->where('id', $old)->value('reserved_qty'), (int) DB::table('stock_units')->where('id', $new)->value('reserved_qty')]);
        $lifo = $this->product('SAND', 'LIFO');
        $o2 = $this->unit($lifo, 10, 'Z1-A1-R1-S1-B01', ['received_at' => now()->subDays(10)]);
        $n2 = $this->unit($lifo, 10, 'Z1-A1-R1-S1-B03', ['received_at' => now()->subDay()]);
        $this->allocate($this->order([['product_id' => $lifo, 'ordered_qty' => 10]])['id']);
        $this->assertSame([0, 10], [(int) DB::table('stock_units')->where('id', $o2)->value('reserved_qty'), (int) DB::table('stock_units')->where('id', $n2)->value('reserved_qty')]);
    }

    public function test_wave_picking_packing_and_dispatch_end_to_end(): void
    {
        $p1 = $this->product('P1');
        $p2 = $this->product('P2');
        $u1 = $this->unit($p1, 100, 'Z1-A1-R1-S1-B02');
        $u2 = $this->unit($p2, 100, 'Z1-A1-R1-S1-B01');
        $a = $this->order([['product_id' => $p1, 'ordered_qty' => 10], ['product_id' => $p2, 'ordered_qty' => 5]]);
        $b = $this->order([['product_id' => $p1, 'ordered_qty' => 20]]);
        $this->allocate($a['id']);
        $this->allocate($b['id']);

        $wave = $this->postJson('/api/v1/waves', ['type' => 'CLUSTER', 'order_ids' => [$a['id'], $b['id']]])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^WV-\d{6}\d{5}$/', $wave['number']);
        $tasks = $wave['tasks'];
        $this->assertCount(3, $tasks);
        $this->assertSame(['Z1-A1-R1-S1-B01', 'Z1-A1-R1-S1-B02', 'Z1-A1-R1-S1-B02'], array_column($tasks, 'location_code')); // pick path by location
        $this->assertEqualsCanonicalizing([1, 1, 2], array_column($tasks, 'slot')); // one tote slot per order
        $this->assertSame('PICKING', DB::table('outbound_orders')->where('id', $a['id'])->value('status'));
        $this->postJson('/api/v1/waves', ['type' => 'WAVE', 'order_ids' => [$a['id']]])->assertUnprocessable(); // already in a wave

        // The scanned LPN must match; picking moves stock out of the warehouse (ISSUE).
        $t = collect($tasks)->firstWhere('product_code', 'P2');
        $this->postJson("/api/v1/pick-tasks/{$t['id']}/pick", ['lpn' => 'WRONG', 'quantity' => 5])->assertUnprocessable();
        $lpn2 = DB::table('stock_units')->where('id', $u2)->value('lpn');
        $this->postJson("/api/v1/pick-tasks/{$t['id']}/pick", ['lpn' => strtolower($lpn2), 'quantity' => 4])->assertUnprocessable(); // short needs a reason
        $this->postJson("/api/v1/pick-tasks/{$t['id']}/pick", ['lpn' => $lpn2, 'quantity' => 4, 'short_reason' => 'Satu pcs rusak'])->assertOk()->assertJsonPath('data.status', 'SHORT');
        $this->assertSame([96, 0], [(int) DB::table('stock_units')->where('id', $u2)->value('quantity'), (int) DB::table('stock_units')->where('id', $u2)->value('reserved_qty')]);
        $this->assertSame(-4, (int) DB::table('stock_movements')->where('stock_unit_id', $u2)->where('type', 'ISSUE')->value('quantity'));
        $lpn1 = DB::table('stock_units')->where('id', $u1)->value('lpn');
        foreach (collect($tasks)->where('product_code', 'P1') as $task) {
            $this->postJson("/api/v1/pick-tasks/{$task['id']}/pick", ['lpn' => $lpn1, 'quantity' => $task['quantity']])->assertOk()->assertJsonPath('data.status', 'DONE');
        }
        $this->assertSame('PICKED', DB::table('outbound_orders')->where('id', $b['id'])->value('status'));
        $this->assertSame('PICKED', DB::table('outbound_orders')->where('id', $a['id'])->value('status'));
        $this->assertSame('COMPLETED', DB::table('waves')->where('id', $wave['id'])->value('status'));

        // Packing: only picked goods, scanned by code or barcode; order b is complete, order a is short by one P2.
        $pkg = $this->postJson("/api/v1/outbound-orders/{$b['id']}/packages")->assertCreated()->json('data');
        $this->postJson("/api/v1/packages/{$pkg['id']}/items", ['scan' => 'P2', 'quantity' => 1])->assertUnprocessable(); // not on this order
        $this->postJson("/api/v1/packages/{$pkg['id']}/items", ['scan' => 'BC-P1', 'quantity' => 21])->assertUnprocessable(); // more than picked
        $this->postJson("/api/v1/packages/{$pkg['id']}/items", ['scan' => 'BC-P1', 'quantity' => 20])->assertOk();
        $this->postJson("/api/v1/outbound-orders/{$b['id']}/pack-complete", [])->assertUnprocessable(); // package still open
        $this->postJson("/api/v1/packages/{$pkg['id']}/close")->assertOk();
        $this->postJson("/api/v1/outbound-orders/{$b['id']}/pack-complete", [])->assertOk()->assertJsonPath('data.status', 'PACKED');

        $pa = $this->postJson("/api/v1/outbound-orders/{$a['id']}/packages")->json('data');
        $this->postJson("/api/v1/packages/{$pa['id']}/items", ['scan' => 'P1', 'quantity' => 10])->assertOk();
        $this->postJson("/api/v1/packages/{$pa['id']}/items", ['scan' => 'P2', 'quantity' => 4])->assertOk();
        $this->postJson("/api/v1/packages/{$pa['id']}/close")->assertOk();
        $operator = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $operator->id, 'role' => 'outbound_operator', 'active' => true]);
        $this->actingAs($operator)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/outbound-orders/{$a['id']}/pack-complete", ['partial_reason' => 'Satu pcs rusak'])->assertForbidden(); // partial shipment needs wave.approve
        $this->actingAs($this->manager)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/outbound-orders/{$a['id']}/pack-complete", [])->assertUnprocessable(); // reason needed
        $this->postJson("/api/v1/outbound-orders/{$a['id']}/pack-complete", ['partial_reason' => 'Satu pcs rusak'])->assertOk()->assertJsonPath('data.status', 'PACKED');

        // Dispatch with the tenant's transport mode; the manifest goes out through the outbox in the same transaction.
        $this->putJson('/api/v1/integration/transport', ['mode' => 'OPTIDELI'])->assertOk();
        $ship = $this->postJson('/api/v1/shipments', ['warehouse_id' => $this->wh, 'driver_name' => 'Joko'])->assertCreated()->json('data');
        $this->assertSame('OPTIDELI', $ship['mode']);
        $this->postJson("/api/v1/shipments/{$ship['id']}/orders", ['order_id' => $a['id']])->assertOk();
        $this->postJson("/api/v1/shipments/{$ship['id']}/orders", ['order_id' => $b['id']])->assertOk();
        $this->postJson("/api/v1/shipments", ['warehouse_id' => $this->wh, 'truck_plate' => 'B 9 XX'])->assertCreated();
        $other = $this->getJson('/api/v1/shipments')->json('data.0');
        $this->postJson("/api/v1/shipments/{$other['id']}/orders", ['order_id' => $a['id']])->assertUnprocessable(); // already loaded elsewhere
        $this->postJson("/api/v1/shipments/{$ship['id']}/dispatch")->assertUnprocessable(); // no carrier or plate
        DB::table('shipments')->where('id', $ship['id'])->update(['truck_plate' => 'B 1234 CD']);
        $manifest = $this->getJson("/api/v1/shipments/{$ship['id']}/manifest")->assertOk()->json('data');
        $this->assertSame(2, $manifest['total_packages']);
        $this->assertSame((10 + 4 + 20) * 500, $manifest['total_weight_g']);
        $this->postJson("/api/v1/shipments/{$ship['id']}/dispatch")->assertOk()->assertJsonPath('data.status', 'DISPATCHED');
        $this->assertSame(['DISPATCHED', 'DISPATCHED'], DB::table('outbound_orders')->whereIn('id', [$a['id'], $b['id']])->pluck('status')->all());
        $event = DB::table('outbox_events')->where('type', 'shipment.dispatched.v1')->first();
        $this->assertNotNull($event);
        $data = json_decode($event->payload, true)['data'];
        $this->assertSame(['OPTIDELI', 2, 'Jl. Pelanggan 1, Jakarta'], [$data['mode'], $data['manifest']['total_packages'], $data['manifest']['orders'][0]['ship_to']]);
        $this->postJson("/api/v1/shipments/{$ship['id']}/dispatch")->assertUnprocessable();
    }

    public function test_outbound_permissions_and_tenant_isolation(): void
    {
        $p = $this->product('P1');
        $this->unit($p, 10, 'Z1-A1-R1-S1-B01');
        $o = $this->order([['product_id' => $p, 'ordered_qty' => 5]]);
        $operator = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $operator->id, 'role' => 'outbound_operator', 'active' => true]);
        $this->actingAs($operator)->withSession(['tenant_id' => $this->tenant]);
        $this->getJson('/api/v1/outbound-orders')->assertOk();
        $this->postJson("/api/v1/outbound-orders/{$o['id']}/allocate")->assertForbidden(); // outbound.manage is for planners
        $this->postJson('/api/v1/waves', ['type' => 'WAVE', 'order_ids' => [$o['id']]])->assertForbidden();

        $foreign = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $foreign, 'slug' => 'lain', 'name' => 'Lain']);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $foreign, 'user_id' => $user->id, 'role' => 'warehouse_manager', 'active' => true]);
        $this->actingAs($user)->withSession(['tenant_id' => $foreign]);
        $this->getJson("/api/v1/outbound-orders/{$o['id']}")->assertNotFound();
        $this->postJson("/api/v1/outbound-orders/{$o['id']}/allocate")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/outbound-orders')->json('total'));
    }
}
