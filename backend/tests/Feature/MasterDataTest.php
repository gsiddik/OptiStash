<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
    }

    private function master(string $resource, array $body, int $status = 201): array
    {
        return $this->postJson("/api/v1/master/{$resource}", $body)->assertStatus($status)->json('data') ?? [];
    }

    private function warehouse(string $code = 'WH1', string $granularity = 'BIN'): string
    {
        return $this->master('warehouses', ['code' => $code, 'name' => 'Gudang '.$code, 'granularity' => $granularity])['id'];
    }

    public function test_products_and_packaging_follow_the_stock_rules(): void
    {
        $uom = $this->master('uoms', ['code' => 'PCS', 'name' => 'Pieces'])['id'];
        $box = $this->master('uoms', ['code' => 'BOX', 'name' => 'Box'])['id'];
        $base = ['code' => 'SKU-1', 'name' => 'Susu UHT', 'base_uom_id' => $uom, 'rotation_policy' => 'FEFO', 'temperature_class' => 'AMBIENT'];

        // FEFO needs expiry tracking.
        $this->postJson('/api/v1/master/products', $base + ['track_lot' => true, 'track_expiry' => false])->assertUnprocessable()->assertJsonValidationErrors('track_expiry');
        $this->postJson('/api/v1/master/products', $base + ['track_lot' => false, 'track_expiry' => true])->assertUnprocessable()->assertJsonValidationErrors('track_lot');
        $this->postJson('/api/v1/master/products', $base + ['track_lot' => true, 'track_expiry' => true, 'shelf_life_days' => 100, 'min_remaining_shelf_life_days' => 200])
            ->assertUnprocessable()->assertJsonValidationErrors('min_remaining_shelf_life_days');
        $product = $this->master('products', $base + ['track_lot' => true, 'track_expiry' => true, 'shelf_life_days' => 180, 'min_remaining_shelf_life_days' => 60]);

        $this->master('product_packaging', ['code' => 'SKU-1-CTN', 'name' => 'Karton 12', 'product_id' => $product['id'], 'uom_id' => $box, 'base_quantity' => 12]);
        // A packaging in the base unit holds exactly one.
        $this->postJson('/api/v1/master/product_packaging', ['code' => 'SKU-1-X', 'name' => 'X', 'product_id' => $product['id'], 'uom_id' => $uom, 'base_quantity' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('base_quantity');
        $this->postJson('/api/v1/master/products', $base + ['rotation_policy' => 'FIFO'])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_tenant_never_sees_or_uses_another_tenants_masters(): void
    {
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'lain', 'name' => 'Lain']);
        $foreign = (string) Str::uuid();
        DB::table('uoms')->insert(['id' => $foreign, 'tenant_id' => $other, 'code' => 'PCS', 'name' => 'Pieces', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/master/products', ['code' => 'P1', 'name' => 'P', 'base_uom_id' => $foreign, 'rotation_policy' => 'FIFO', 'temperature_class' => 'AMBIENT'])
            ->assertUnprocessable()->assertJsonValidationErrors('base_uom_id');
        $this->assertSame(0, $this->getJson('/api/v1/master/uoms')->json('total'));
    }

    public function test_storage_hierarchy_respects_the_warehouse_granularity(): void
    {
        $wh = $this->warehouse('WH1', 'RACK');
        $zone = $this->postJson("/api/v1/warehouses/{$wh}/locations", ['code' => 'z1', 'name' => 'Zona 1'])->assertCreated()->json('data');
        $this->assertSame('ZONE', $zone['level']);
        $this->assertSame('Z1', $zone['code']);

        $generated = $this->postJson("/api/v1/warehouses/{$wh}/locations/generate", ['parent_id' => $zone['id'], 'prefix' => 'A', 'count' => 3])->assertCreated()->json('data');
        $this->assertSame(['created' => 3, 'first' => 'Z1-A01', 'last' => 'Z1-A03'], $generated);
        $aisle = DB::table('storage_nodes')->where('code', 'Z1-A01')->first();
        $this->assertSame('AISLE', $aisle->level);

        $rack = $this->postJson("/api/v1/warehouses/{$wh}/locations", ['parent_id' => $aisle->id, 'code' => 'Z1-A01-R1', 'name' => 'Rak 1'])->assertCreated()->json('data');
        $this->assertSame('RACK', $rack['level']);
        // The warehouse stops at rack level; a shelf is refused, and the limit cannot be narrowed below existing locations.
        $this->postJson("/api/v1/warehouses/{$wh}/locations", ['parent_id' => $rack['id'], 'code' => 'Z1-A01-R1-S1', 'name' => 'Tingkat'])->assertUnprocessable();
        $this->putJson("/api/v1/master/warehouses/{$wh}", ['name' => 'Gudang WH1', 'granularity' => 'AISLE'])->assertUnprocessable()->assertJsonValidationErrors('granularity');
        // Codes are unique per warehouse; a generated run that collides creates nothing.
        $this->postJson("/api/v1/warehouses/{$wh}/locations", ['parent_id' => $zone['id'], 'code' => 'Z1-A02', 'name' => 'Dup'])->assertUnprocessable();
        $this->postJson("/api/v1/warehouses/{$wh}/locations/generate", ['parent_id' => $zone['id'], 'prefix' => 'A', 'count' => 5, 'start' => 3])->assertUnprocessable();
        $this->assertSame(3, DB::table('storage_nodes')->where('level', 'AISLE')->count());
        $this->postJson("/api/v1/warehouses/{$wh}/locations/generate", ['prefix' => 'B', 'count' => 501])->assertUnprocessable();
    }

    public function test_blocking_a_location_blocks_everything_below_and_is_audited(): void
    {
        $wh = $this->warehouse();
        $zone = $this->postJson("/api/v1/warehouses/{$wh}/locations", ['code' => 'Z1', 'name' => 'Zona'])->json('data');
        $aisle = $this->postJson("/api/v1/warehouses/{$wh}/locations", ['parent_id' => $zone['id'], 'code' => 'Z1-A1', 'name' => 'Lorong'])->json('data');

        $this->postJson("/api/v1/locations/{$zone['id']}/block", ['blocked' => true, 'reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/locations/{$zone['id']}/block", ['blocked' => true, 'reason' => 'Lantai sedang diperbaiki'])->assertOk()->assertJsonPath('data.blocked', true);
        $tree = collect($this->getJson("/api/v1/warehouses/{$wh}/locations")->json('data'))->keyBy('code');
        $this->assertTrue($tree['Z1-A1']['effective_blocked']);
        $this->assertFalse((bool) $tree['Z1-A1']['blocked']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'storage.node_blocked']);

        $this->postJson("/api/v1/locations/{$zone['id']}/deactivate", ['reason' => 'Tidak dipakai lagi'])->assertUnprocessable(); // still has an active aisle
        $this->postJson("/api/v1/locations/{$aisle['id']}/deactivate", ['reason' => 'Tidak dipakai lagi'])->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_transport_integration_mode(): void
    {
        $this->getJson('/api/v1/integration/transport')->assertOk()->assertJsonPath('data.mode', 'MANUAL');
        $this->putJson('/api/v1/integration/transport', ['mode' => 'THIRD_PARTY_API', 'api_url' => 'http://insecure.example'])->assertUnprocessable();
        $this->putJson('/api/v1/integration/transport', ['mode' => 'THIRD_PARTY_API', 'api_url' => 'https://tms.example/api'])->assertOk()->assertJsonPath('data.mode', 'THIRD_PARTY_API');
        $this->putJson('/api/v1/integration/transport', ['mode' => 'OPTIDELI', 'api_url' => 'https://ignored.example'])->assertOk()->assertJsonPath('data.api_url', null);
        $this->putJson('/api/v1/integration/transport', ['mode' => 'FAX'])->assertUnprocessable();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'integration.transport_changed')->count());
    }

    public function test_a_supplier_gets_portal_access_but_never_the_internal_screens(): void
    {
        $partner = $this->master('partners', ['code' => 'SUP1', 'name' => 'PT Pemasok', 'type' => 'SUPPLIER'])['id'];
        $customer = $this->master('partners', ['code' => 'CUS1', 'name' => 'PT Pelanggan', 'type' => 'CUSTOMER'])['id'];
        $password = 'Pemasok-Aman-2026!';
        $this->postJson("/api/v1/partners/{$customer}/portal-users", ['name' => 'X', 'email' => 'x@pelanggan.test', 'password' => $password])->assertUnprocessable();
        $this->postJson("/api/v1/partners/{$partner}/portal-users", ['name' => 'Budi', 'email' => 'budi@pemasok.test', 'password' => 'lemah'])->assertUnprocessable();
        $this->postJson("/api/v1/partners/{$partner}/portal-users", ['name' => 'Budi', 'email' => 'budi@pemasok.test', 'password' => $password])->assertCreated();
        // An existing account is never taken over.
        $this->postJson("/api/v1/partners/{$partner}/portal-users", ['name' => 'Budi', 'email' => 'BUDI@pemasok.test', 'password' => $password])->assertUnprocessable();

        $membership = DB::table('memberships')->where('supplier_id', $partner)->first();
        $this->assertSame('supplier', $membership->role);

        // Sign in as the supplier: allowed to its portal, refused by every internal route.
        auth()->forgetGuards();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $supplier = User::where('email', 'budi@pemasok.test')->first();
        $this->actingAs($supplier)->withSession(['tenant_id' => $this->tenant, 'external' => true]);
        $this->getJson('/api/v1/master/schema')->assertForbidden();
        $this->getJson('/api/v1/master/warehouses')->assertForbidden();
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(['supplier.portal'], $me->json('data.permissions'));
        $this->assertTrue($me->json('data.tenant') !== null);

        // Deactivating the supplier partner locks the portal user out.
        DB::table('partners')->where('id', $partner)->update(['active' => false]);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_an_operator_without_master_manage_cannot_write(): void
    {
        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenant]);
        $this->getJson('/api/v1/master/warehouses')->assertOk();
        $this->postJson('/api/v1/master/uoms', ['code' => 'PCS', 'name' => 'Pieces'])->assertForbidden();
        $this->putJson('/api/v1/integration/transport', ['mode' => 'MANUAL'])->assertForbidden();
    }
}
