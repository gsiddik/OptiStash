<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class InboundTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private string $wh;

    private string $dock;

    private string $supplier;

    private string $milk;

    private string $rice;

    /** @var list<string> bin ids, B-01..B-03 (B-01 capacity 100, others unlimited) */
    private array $bins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
        $this->wh = $this->make('warehouses', ['code' => 'WH1', 'name' => 'Gudang 1', 'granularity' => 'BIN'], true);
        $this->dock = $this->make('docks', ['code' => 'D1', 'name' => 'Dock 1', 'warehouse_id' => $this->wh], true);
        $this->supplier = $this->make('partners', ['code' => 'SUP1', 'name' => 'PT Pemasok', 'type' => 'SUPPLIER'], true);
        $pcs = $this->make('uoms', ['code' => 'PCS', 'name' => 'Pieces'], true);
        $this->milk = $this->make('products', ['code' => 'MILK', 'name' => 'Susu', 'base_uom_id' => $pcs, 'rotation_policy' => 'FEFO', 'track_lot' => true, 'track_expiry' => true, 'min_remaining_shelf_life_days' => 30, 'temperature_class' => 'AMBIENT'], true);
        $this->rice = $this->make('products', ['code' => 'RICE', 'name' => 'Beras', 'base_uom_id' => $pcs, 'rotation_policy' => 'FIFO', 'track_lot' => false, 'track_expiry' => false, 'temperature_class' => 'AMBIENT'], true);
        $zone = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['code' => 'Z1', 'name' => 'Z'])->json('data');
        foreach ([$zone['id']] as $z) {
            $aisle = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $z, 'code' => 'Z1-A1', 'name' => 'A'])->json('data');
            $rack = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $aisle['id'], 'code' => 'Z1-A1-R1', 'name' => 'R'])->json('data');
            $shelf = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['parent_id' => $rack['id'], 'code' => 'Z1-A1-R1-S1', 'name' => 'S'])->json('data');
            $this->postJson("/api/v1/warehouses/{$this->wh}/locations/generate", ['parent_id' => $shelf['id'], 'prefix' => 'B', 'count' => 3])->assertCreated();
        }
        $this->bins = DB::table('storage_nodes')->where('level', 'BIN')->orderBy('code')->pluck('id')->all();
        DB::table('storage_nodes')->where('id', $this->bins[0])->update(['capacity_base_units' => 100]);
    }

    private function make(string $resource, array $body, bool $master = false): string
    {
        return $this->postJson($master ? "/api/v1/master/{$resource}" : "/api/v1/{$resource}", $body)->assertCreated()->json('data.id');
    }

    private function asn(array $lines, string $status = 'CONFIRMED'): array
    {
        $asn = $this->postJson('/api/v1/asns', [
            'supplier_id' => $this->supplier, 'warehouse_id' => $this->wh, 'expected_date' => now()->addDay()->toDateString(), 'reference' => 'PO-1', 'lines' => $lines,
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $asn['status']);
        $this->assertMatchesRegularExpression('/^ASN-\d{6}\d{5}$/', $asn['number']);
        if ($status !== 'DRAFT') {
            $this->postJson("/api/v1/asns/{$asn['id']}/submit")->assertOk();
        }
        if ($status === 'CONFIRMED') {
            $this->postJson("/api/v1/asns/{$asn['id']}/confirm")->assertOk();
        }

        return $this->getJson("/api/v1/asns/{$asn['id']}")->json('data');
    }

    private function arrived(array $lines): array
    {
        $asn = $this->asn($lines);
        $booking = $this->postJson('/api/v1/dock-bookings', ['dock_id' => $this->dock, 'asn_id' => $asn['id'], 'slot_start' => now()->addDay()->setTime(8, 0)->toIso8601String(), 'slot_end' => now()->addDay()->setTime(10, 0)->toIso8601String()])
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/dock-bookings/{$booking['id']}/gate-in", ['truck_plate' => 'B 1234 XY'])->assertOk();

        return $this->getJson("/api/v1/asns/{$asn['id']}")->json('data');
    }

    public function test_asn_lifecycle_and_dock_booking_rules(): void
    {
        $asn = $this->asn([['product_id' => $this->rice, 'expected_qty' => 50]], 'DRAFT');
        $this->postJson("/api/v1/asns/{$asn['id']}/confirm")->assertUnprocessable(); // not submitted
        $this->postJson('/api/v1/asns', ['supplier_id' => $this->supplier, 'warehouse_id' => $this->wh, 'expected_date' => now()->subDay()->toDateString(), 'lines' => [['product_id' => $this->rice, 'expected_qty' => 1]]])->assertUnprocessable();
        $this->postJson('/api/v1/asns', ['supplier_id' => $this->supplier, 'warehouse_id' => $this->wh, 'expected_date' => now()->toDateString(), 'lines' => [['product_id' => $this->rice, 'expected_qty' => 1], ['product_id' => $this->rice, 'expected_qty' => 2]]])->assertUnprocessable();
        $this->putJson("/api/v1/asns/{$asn['id']}", ['supplier_id' => $this->supplier, 'warehouse_id' => $this->wh, 'expected_date' => now()->addDays(2)->toDateString(), 'lines' => [['product_id' => $this->rice, 'expected_qty' => 60]]])->assertOk();
        $this->postJson("/api/v1/asns/{$asn['id']}/submit")->assertOk();
        $this->putJson("/api/v1/asns/{$asn['id']}", ['supplier_id' => $this->supplier, 'warehouse_id' => $this->wh, 'expected_date' => now()->addDays(2)->toDateString(), 'lines' => [['product_id' => $this->rice, 'expected_qty' => 70]]])->assertUnprocessable();
        // Booking needs a confirmed ASN; overlapping slots on one dock clash.
        $slot = ['dock_id' => $this->dock, 'asn_id' => $asn['id'], 'slot_start' => now()->addDay()->setTime(8, 0)->toIso8601String(), 'slot_end' => now()->addDay()->setTime(9, 0)->toIso8601String()];
        $this->postJson('/api/v1/dock-bookings', $slot)->assertUnprocessable();
        $this->postJson("/api/v1/asns/{$asn['id']}/confirm")->assertOk();
        $this->postJson('/api/v1/dock-bookings', $slot)->assertCreated();
        $other = $this->asn([['product_id' => $this->rice, 'expected_qty' => 5]]);
        $this->postJson('/api/v1/dock-bookings', ['asn_id' => $other['id'], 'slot_start' => now()->addDay()->setTime(8, 30)->toIso8601String(), 'slot_end' => now()->addDay()->setTime(9, 30)->toIso8601String()] + $slot)->assertUnprocessable();
        $this->postJson('/api/v1/dock-bookings', ['asn_id' => $other['id'], 'slot_start' => now()->addDay()->setTime(9, 0)->toIso8601String(), 'slot_end' => now()->addDay()->setTime(10, 0)->toIso8601String()] + $slot)->assertCreated();
        // Nothing can be received before the truck is at the gate.
        $line = $this->getJson("/api/v1/asns/{$asn['id']}")->json('data.lines.0.id');
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 1])->assertUnprocessable();
    }

    public function test_receiving_creates_lpn_stock_units_and_directs_putaway(): void
    {
        $asn = $this->arrived([['product_id' => $this->rice, 'expected_qty' => 150], ['product_id' => $this->milk, 'expected_qty' => 20]]);
        $this->assertSame('ARRIVED', $asn['status']);
        [$riceLine, $milkLine] = [$asn['lines'][0]['id'], $asn['lines'][1]['id']];

        // Lot and expiry are enforced per product; over-receipt is refused.
        $this->postJson("/api/v1/asn-lines/{$milkLine}/receive", ['quantity' => 10])->assertUnprocessable();
        $this->postJson("/api/v1/asn-lines/{$milkLine}/receive", ['quantity' => 10, 'lot_no' => 'L1'])->assertUnprocessable();
        $this->postJson("/api/v1/asn-lines/{$riceLine}/receive", ['quantity' => 151])->assertUnprocessable();

        $a = $this->postJson("/api/v1/asn-lines/{$riceLine}/receive", ['quantity' => 90])->assertCreated()->json('data');
        $b = $this->postJson("/api/v1/asn-lines/{$riceLine}/receive", ['quantity' => 60])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^LPN-\d{6}\d{6}$/', $a['lpn']);
        $this->assertNotSame($a['lpn'], $b['lpn']);
        $this->assertSame('PENDING_PUTAWAY', $a['status']);
        $this->assertSame('RECEIVING', DB::table('asns')->where('id', $asn['id'])->value('status'));
        $this->assertSame(150, (int) DB::table('asn_lines')->where('id', $riceLine)->value('received_qty'));

        // First unit (90) fits the 100-capacity bin B-01; the second (60) does not, and goes to the next free bin.
        $tasks = collect($this->getJson('/api/v1/putaway-tasks')->json('data'))->keyBy('lpn');
        $this->assertSame('Z1-A1-R1-S1-B01', $tasks[$a['lpn']]['recommended_code']);
        $this->assertSame('Z1-A1-R1-S1-B02', $tasks[$b['lpn']]['recommended_code']);
        $choices = collect($this->getJson("/api/v1/putaway-tasks/{$tasks[$b['lpn']]['id']}/locations")->assertOk()->json('data'));
        $this->assertSame(['Z1-A1-R1-S1-B02', 'Z1-A1-R1-S1-B03'], $choices->pluck('code')->all());
        $this->assertTrue($choices[0]['recommended']);

        $this->postJson("/api/v1/putaway-tasks/{$tasks[$a['lpn']]['id']}/complete", [])->assertOk()->assertJsonPath('data.status', 'AVAILABLE')->assertJsonPath('data.location_code', 'Z1-A1-R1-S1-B01');
        // An override needs a reason and a usable location (B-01 is now full: capacity 100, 90 stored).
        $this->postJson("/api/v1/putaway-tasks/{$tasks[$b['lpn']]['id']}/complete", ['location_id' => $this->bins[2]])->assertUnprocessable();
        $this->postJson("/api/v1/putaway-tasks/{$tasks[$b['lpn']]['id']}/complete", ['location_id' => $this->bins[0], 'override_reason' => 'Lebih dekat ke dock'])->assertUnprocessable();
        $this->postJson("/api/v1/putaway-tasks/{$tasks[$b['lpn']]['id']}/complete", ['location_id' => $this->bins[2], 'override_reason' => 'Lebih dekat ke dock'])->assertOk()->assertJsonPath('data.location_code', 'Z1-A1-R1-S1-B03');
        $this->assertTrue((bool) DB::table('putaway_tasks')->where('stock_unit_id', $b['id'])->value('overridden'));
        $this->assertSame(['RECEIVE', 'PUTAWAY'], DB::table('stock_movements')->where('stock_unit_id', $b['id'])->orderBy('created_at')->orderBy('type', 'desc')->pluck('type')->all());

        $this->postJson("/api/v1/asns/{$asn['id']}/complete", [])->assertUnprocessable(); // milk not received: reason needed
        $this->postJson("/api/v1/asns/{$asn['id']}/complete", ['reason' => 'Susu tidak dikirim'])->assertOk()->assertJsonPath('data.status', 'RECEIVED');
        $this->getJson("/api/v1/stock-units/{$a['id']}/label")->assertOk()->assertJsonPath('data.barcode', $a['lpn']);
    }

    public function test_nonconforming_goods_are_quarantined_until_a_quality_decision(): void
    {
        $asn = $this->arrived([['product_id' => $this->milk, 'expected_qty' => 100]]);
        $line = $asn['lines'][0]['id'];
        $soon = now()->addDays(10)->toDateString();
        // Too little shelf life: must be flagged nonconforming to be accepted at all.
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 10, 'lot_no' => 'L1', 'expiry_date' => $soon])->assertUnprocessable();
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 10, 'lot_no' => 'L1', 'expiry_date' => $soon, 'nonconforming' => true])->assertUnprocessable(); // no description
        $q = $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 10, 'lot_no' => 'L1', 'expiry_date' => $soon, 'nonconforming' => true, 'description' => 'Sisa umur simpan 10 hari'])->assertCreated()->json('data');
        $this->assertSame('QUARANTINE', $q['status']);
        $this->assertSame(0, DB::table('putaway_tasks')->where('stock_unit_id', $q['id'])->count());
        $nc = $this->getJson('/api/v1/nonconformances')->assertOk()->json('data.0');
        $this->assertSame('EXPIRED', $nc['category']);
        $this->assertMatchesRegularExpression('/^NC-\d{6}\d{5}$/', $nc['number']);

        $good = $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 20, 'lot_no' => 'L2', 'expiry_date' => now()->addDays(200)->toDateString()])->assertCreated()->json('data');
        $this->assertSame('PENDING_PUTAWAY', $good['status']);
        // QC can still quarantine a unit waiting for putaway (its putaway task is cancelled), then release it.
        $this->postJson("/api/v1/stock-units/{$good['id']}/decision", ['decision' => 'QUARANTINE', 'reason' => 'Kemasan penyok'])->assertOk()->assertJsonPath('data.status', 'QUARANTINE');
        $this->assertSame('CANCELLED', DB::table('putaway_tasks')->where('stock_unit_id', $good['id'])->value('status'));
        $this->postJson("/api/v1/stock-units/{$good['id']}/decision", ['decision' => 'RELEASE', 'reason' => 'Setelah pemeriksaan ulang'])->assertOk()->assertJsonPath('data.status', 'PENDING_PUTAWAY');
        $this->assertSame(1, DB::table('putaway_tasks')->where('stock_unit_id', $good['id'])->where('status', 'OPEN')->count());

        $this->postJson("/api/v1/stock-units/{$q['id']}/decision", ['decision' => 'REJECT', 'reason' => 'Dikembalikan ke pemasok'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame('CLOSED', DB::table('nonconformances')->where('stock_unit_id', $q['id'])->value('status'));
        $this->postJson("/api/v1/stock-units/{$q['id']}/decision", ['decision' => 'RELEASE', 'reason' => 'Sudah ditolak'])->assertUnprocessable();
        $this->assertGreaterThanOrEqual(5, DB::table('audit_events')->where('tenant_id', $this->tenant)->where('action', 'like', 'qc.%')->count() + DB::table('audit_events')->where('action', 'like', 'nonconformance.%')->count());
    }

    public function test_operators_only_do_what_their_role_allows(): void
    {
        $this->arrived([['product_id' => $this->rice, 'expected_qty' => 10]]);
        $operator = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $operator->id, 'role' => 'outbound_operator', 'active' => true]);
        $this->actingAs($operator)->withSession(['tenant_id' => $this->tenant]);
        $line = DB::table('asn_lines')->value('id');
        $this->getJson('/api/v1/asns')->assertForbidden(); // outbound operator has no inbound.view
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 1])->assertForbidden();

        $inbound = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $inbound->id, 'role' => 'inbound_operator', 'active' => true]);
        $this->actingAs($inbound)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 1])->assertCreated();
        $unit = DB::table('stock_units')->value('id');
        $this->postJson("/api/v1/stock-units/{$unit}/decision", ['decision' => 'QUARANTINE', 'reason' => 'Bukan tugas operator'])->assertForbidden(); // qc.inspect is for QC
        $asn = DB::table('asns')->value('id');
        $this->postJson("/api/v1/asns/{$asn}/cancel", ['reason' => 'Tidak berwenang'])->assertForbidden();
    }

    public function test_a_supplier_only_sees_and_changes_its_own_asns(): void
    {
        $other = $this->make('partners', ['code' => 'SUP2', 'name' => 'PT Lain', 'type' => 'SUPPLIER'], true);
        $password = 'Pemasok-Aman-2026!';
        $this->postJson("/api/v1/partners/{$this->supplier}/portal-users", ['name' => 'A', 'email' => 'a@pemasok.test', 'password' => $password])->assertCreated();
        $this->postJson("/api/v1/partners/{$other}/portal-users", ['name' => 'B', 'email' => 'b@lain.test', 'password' => $password])->assertCreated();
        $mine = $this->asn([['product_id' => $this->rice, 'expected_qty' => 5]], 'DRAFT');

        $this->actingAs(User::where('email', 'b@lain.test')->first())->withSession(['tenant_id' => $this->tenant, 'external' => true]);
        $this->getJson('/api/v1/asns')->assertForbidden(); // internal route
        $this->getJson("/api/v1/supplier/asns/{$mine['id']}")->assertNotFound();
        $this->postJson("/api/v1/supplier/asns/{$mine['id']}/submit")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/supplier/asns')->json('total'));
        $created = $this->postJson('/api/v1/supplier/asns', [
            'supplier_id' => $this->supplier, // ignored: the supplier comes from the membership
            'warehouse_id' => $this->wh, 'expected_date' => now()->addDays(3)->toDateString(), 'lines' => [['product_id' => $this->rice, 'expected_qty' => 40]],
        ])->assertCreated()->json('data');
        $this->assertSame($other, $created['supplier_id']);
        $this->assertSame('PORTAL', $created['source']);
        $this->postJson("/api/v1/supplier/asns/{$created['id']}/submit")->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->postJson("/api/v1/supplier/asns/{$created['id']}/cancel", ['reason' => 'Salah kirim data'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame(1, $this->getJson('/api/v1/supplier/asns')->json('total'));

        // Staff confirm; afterwards the supplier cannot cancel it any more.
        $this->actingAs(User::where('email', 'a@pemasok.test')->first())->withSession(['tenant_id' => $this->tenant, 'external' => true]);
        $this->postJson("/api/v1/supplier/asns/{$mine['id']}/submit")->assertOk();
        $manager = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $manager->id, 'role' => 'warehouse_manager', 'active' => true]);
        $this->actingAs($manager)->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/asns/{$mine['id']}/confirm")->assertOk();
        $this->actingAs(User::where('email', 'a@pemasok.test')->first())->withSession(['tenant_id' => $this->tenant, 'external' => true]);
        $this->postJson("/api/v1/supplier/asns/{$mine['id']}/cancel", ['reason' => 'Ingin batalkan'])->assertUnprocessable();
    }

    public function test_tenants_are_isolated(): void
    {
        $asn = $this->asn([['product_id' => $this->rice, 'expected_qty' => 5]], 'DRAFT');
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'lain', 'name' => 'Lain']);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $other, 'user_id' => $user->id, 'role' => 'warehouse_manager', 'active' => true]);
        $this->actingAs($user)->withSession(['tenant_id' => $other]);
        $this->getJson("/api/v1/asns/{$asn['id']}")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/asns')->json('total'));
        $this->postJson("/api/v1/asns/{$asn['id']}/submit")->assertNotFound();
    }
}
