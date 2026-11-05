<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class QualityTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $manager;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
        $this->manager = auth()->user();
        $this->second = $this->member('quality_auditor');
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $u->id, 'role' => $role, 'active' => true]);

        return $u;
    }

    private function as(User $u): void
    {
        $this->actingAs($u)->withSession(['tenant_id' => $this->tenant]);
    }

    public function test_capa_needs_root_cause_actions_and_an_independent_verification(): void
    {
        $nc = $this->postJson('/api/v1/nonconformances', ['source' => 'AUDIT', 'category' => 'DAMAGED', 'title' => 'Palet rusak', 'description' => 'Rak B3 penyok saat audit internal'])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^NC-\d{6}\d{5}$/', $nc['number']);
        $this->postJson('/api/v1/nonconformances', ['source' => 'RECEIPT', 'category' => 'DAMAGED', 'title' => 'x', 'description' => 'tidak boleh manual'])->assertUnprocessable();

        $owner = $this->member('warehouse_manager');
        $capa = $this->postJson('/api/v1/capas', ['nonconformance_id' => $nc['id'], 'type' => 'CORRECTIVE', 'title' => 'Perbaiki rak B3', 'description' => 'Ganti rak dan periksa beban', 'owner_id' => $owner->id, 'due_date' => now()->addDays(14)->toDateString()])->assertCreated()->json('data');
        $this->assertSame('OPEN', $capa['status']);
        $this->postJson("/api/v1/capas/{$capa['id']}/actions", ['description' => 'Ganti rak', 'assignee_id' => $owner->id, 'due_date' => now()->addDays(7)->toDateString()])->assertUnprocessable(); // no root cause yet

        $this->postJson("/api/v1/capas/{$capa['id']}/analysis", ['method' => 'FIVE_WHY', 'rca' => ['whys' => ['Rak penyok', 'Forklift menabrak']], 'root_cause' => 'Jalur forklift sempit'])->assertUnprocessable(); // 3 to 7 whys
        $this->postJson("/api/v1/capas/{$capa['id']}/analysis", ['method' => 'FISHBONE', 'rca' => ['causes' => ['WEATHER' => ['hujan']]], 'root_cause' => 'Jalur forklift sempit'])->assertUnprocessable();
        $this->postJson("/api/v1/capas/{$capa['id']}/analysis", ['method' => 'FIVE_WHY', 'rca' => ['whys' => ['Rak penyok', 'Forklift menabrak', 'Jalur sempit']], 'root_cause' => 'Jalur forklift sempit'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->postJson("/api/v1/capas/{$capa['id']}/analysis", ['method' => 'FISHBONE', 'rca' => ['causes' => ['METHOD' => ['Tidak ada marka jalur'], 'MAN' => ['Operator terburu-buru']]], 'root_cause' => 'Tidak ada marka jalur'])->assertOk()->assertJsonPath('data.rca_method', 'FISHBONE');

        $this->postJson("/api/v1/capas/{$capa['id']}/verify", ['notes' => 'Belum ada tindakan'])->assertUnprocessable();
        $action = $this->postJson("/api/v1/capas/{$capa['id']}/actions", ['description' => 'Pasang marka jalur', 'assignee_id' => $owner->id, 'due_date' => now()->addDays(7)->toDateString()])->assertCreated()->json('data.actions.0');
        $this->postJson("/api/v1/capa-actions/{$action['id']}/complete")->assertForbidden(); // only the assignee
        $this->as($owner);
        $this->postJson("/api/v1/capa-actions/{$action['id']}/complete")->assertOk();
        $this->postJson("/api/v1/capas/{$capa['id']}/verify", ['notes' => 'Saya sendiri yang memeriksa'])->assertForbidden(); // the owner cannot verify
        $this->as($this->manager);
        $this->postJson("/api/v1/capas/{$capa['id']}/close")->assertUnprocessable(); // not verified
        $this->postJson("/api/v1/capas/{$capa['id']}/verify", ['notes' => 'Marka terpasang, tidak ada insiden 30 hari'])->assertOk()->assertJsonPath('data.status', 'VERIFIED');
        $this->postJson("/api/v1/capas/{$capa['id']}/close")->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertSame('CLOSED', DB::table('nonconformances')->where('id', $nc['id'])->value('status'));
        $this->assertSame(['capa.created', 'capa.analysed', 'capa.verified', 'capa.closed'], array_values(array_intersect(['capa.created', 'capa.analysed', 'capa.verified', 'capa.closed'], DB::table('audit_events')->orderBy('created_at')->pluck('action')->all())));
    }

    public function test_an_overdue_capa_is_flagged(): void
    {
        $capa = $this->postJson('/api/v1/capas', ['type' => 'PREVENTIVE', 'title' => 'Latih operator', 'description' => 'Pelatihan forklift', 'owner_id' => $this->manager->id, 'due_date' => now()->addDay()->toDateString()])->assertCreated()->json('data');
        $this->assertFalse($this->getJson("/api/v1/capas/{$capa['id']}")->json('data.overdue'));
        DB::table('capas')->where('id', $capa['id'])->update(['due_date' => now()->subDays(3)->toDateString()]);
        $this->assertTrue($this->getJson('/api/v1/capas')->json('data.0.overdue'));
        $this->assertSame(1, collect($this->getJson('/api/v1/quality/iso-map')->json('data'))->firstWhere('clause', '10.2')['metrics']['capa_terlambat']);
    }

    public function test_an_instrument_out_of_calibration_cannot_be_used_for_receiving(): void
    {
        $this->postJson('/api/v1/instruments', ['code' => 'th-01', 'name' => 'Termometer dock', 'kind' => 'THERMOMETER', 'interval_days' => 180])->assertCreated()->assertJsonPath('data.usability.usable', false);
        $i = DB::table('instruments')->first();
        $this->postJson('/api/v1/instruments', ['code' => 'TH-01', 'name' => 'Dup', 'kind' => 'THERMOMETER', 'interval_days' => 180])->assertUnprocessable();
        $this->postJson("/api/v1/instruments/{$i->id}/calibrations", ['calibrated_on' => now()->addDay()->toDateString(), 'performed_by' => 'PT Kalibrasi', 'certificate_no' => 'C-1', 'result' => 'PASS'])->assertUnprocessable();
        $this->postJson("/api/v1/instruments/{$i->id}/calibrations", ['calibrated_on' => now()->subDays(200)->toDateString(), 'performed_by' => 'PT Kalibrasi', 'certificate_no' => 'C-1', 'result' => 'PASS'])->assertCreated()
            ->assertJsonPath('data.usability.usable', false); // due 20 days ago
        $this->postJson("/api/v1/instruments/{$i->id}/calibrations", ['calibrated_on' => now()->toDateString(), 'performed_by' => 'PT Kalibrasi', 'certificate_no' => 'C-2', 'result' => 'FAIL'])->assertCreated()->assertJsonPath('data.usability.reason', 'Kalibrasi terakhir gagal.');

        // Chilled goods need a usable instrument at the dock.
        $wh = $this->postJson('/api/v1/master/warehouses', ['code' => 'WH1', 'name' => 'G', 'granularity' => 'ZONE'])->json('data.id');
        $sup = $this->postJson('/api/v1/master/partners', ['code' => 'S1', 'name' => 'S', 'type' => 'SUPPLIER'])->json('data.id');
        $uom = $this->postJson('/api/v1/master/uoms', ['code' => 'PCS', 'name' => 'P'])->json('data.id');
        $ice = $this->postJson('/api/v1/master/products', ['code' => 'ICE', 'name' => 'Es krim', 'base_uom_id' => $uom, 'rotation_policy' => 'FIFO', 'track_lot' => true, 'temperature_class' => 'FROZEN'])->assertCreated()->json('data.id');
        $asn = $this->postJson('/api/v1/asns', ['supplier_id' => $sup, 'warehouse_id' => $wh, 'expected_date' => now()->addDay()->toDateString(), 'lines' => [['product_id' => $ice, 'expected_qty' => 10]]])->json('data');
        $this->postJson("/api/v1/asns/{$asn['id']}/submit");
        $this->postJson("/api/v1/asns/{$asn['id']}/confirm");
        $dock = $this->postJson('/api/v1/master/docks', ['code' => 'D1', 'name' => 'D', 'warehouse_id' => $wh])->json('data.id');
        $b = $this->postJson('/api/v1/dock-bookings', ['dock_id' => $dock, 'asn_id' => $asn['id'], 'slot_start' => now()->addDay()->setTime(8, 0)->toIso8601String(), 'slot_end' => now()->addDay()->setTime(9, 0)->toIso8601String()])->json('data.id');
        $this->postJson("/api/v1/dock-bookings/{$b}/gate-in")->assertOk();
        $line = DB::table('asn_lines')->value('id');
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 5, 'lot_no' => 'L1'])->assertUnprocessable(); // no instrument chosen
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 5, 'lot_no' => 'L1', 'instrument_id' => $i->id])->assertUnprocessable(); // last calibration failed
        $this->postJson("/api/v1/instruments/{$i->id}/calibrations", ['calibrated_on' => now()->toDateString(), 'performed_by' => 'PT Kalibrasi', 'certificate_no' => 'C-3', 'result' => 'PASS'])->assertCreated()->assertJsonPath('data.usability.usable', true);
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 5, 'lot_no' => 'L1', 'instrument_id' => $i->id])->assertCreated();
        $this->assertSame('TH-01', json_decode(DB::table('audit_events')->where('action', 'receiving.received')->value('metadata'), true)['instrument']);
        $this->postJson("/api/v1/instruments/{$i->id}/deactivate", ['reason' => 'Rusak dan diganti'])->assertOk();
        $this->postJson("/api/v1/asn-lines/{$line}/receive", ['quantity' => 5, 'lot_no' => 'L2', 'instrument_id' => $i->id])->assertUnprocessable();
    }

    public function test_controlled_documents_have_independent_approval_and_one_effective_version(): void
    {
        $doc = $this->postJson('/api/v1/documents', ['code' => 'sop-001', 'title' => 'Penerimaan barang', 'category' => 'SOP', 'content' => 'Langkah 1. Periksa segel.', 'change_summary' => 'Versi awal'])->assertCreated()->json('data');
        $v1 = $doc['versions'][0];
        $this->assertSame(['DRAFT', 1], [$v1['status'], $v1['version_no']]);
        $this->postJson("/api/v1/documents/{$doc['id']}/versions", ['content' => 'x', 'change_summary' => 'y'])->assertUnprocessable(); // a draft is still open
        $this->putJson("/api/v1/document-versions/{$v1['id']}", ['content' => 'Langkah 1. Periksa segel. Langkah 2. Hitung.', 'change_summary' => 'Tambah langkah'])->assertOk();
        $this->postJson("/api/v1/document-versions/{$v1['id']}/submit")->assertOk();
        $this->putJson("/api/v1/document-versions/{$v1['id']}", ['content' => 'diubah setelah diajukan', 'change_summary' => 'z'])->assertUnprocessable();
        $this->postJson("/api/v1/document-versions/{$v1['id']}/approve")->assertForbidden(); // the author cannot approve
        $this->as($this->member('warehouse_manager'));
        $this->postJson("/api/v1/document-versions/{$v1['id']}/approve")->assertOk();
        $this->assertSame('1', (string) $this->getJson('/api/v1/documents')->json('data.0.effective_version'));

        // Tampering with an approved version is caught: the checksum no longer matches the content on the next approval path.
        $this->as($this->manager);
        $doc2 = $this->postJson("/api/v1/documents/{$doc['id']}/versions", ['content' => 'Versi 2', 'change_summary' => 'Revisi'])->assertCreated()->json('data');
        $v2 = $doc2['versions'][0];
        $this->postJson("/api/v1/document-versions/{$v2['id']}/submit")->assertOk();
        DB::table('document_versions')->where('id', $v2['id'])->update(['content' => 'Versi 2 diubah langsung di database']);
        $this->as($this->member('warehouse_manager'));
        $this->postJson("/api/v1/document-versions/{$v2['id']}/approve")->assertStatus(409);
        DB::table('document_versions')->where('id', $v2['id'])->update(['content' => 'Versi 2']);
        $this->postJson("/api/v1/document-versions/{$v2['id']}/approve")->assertOk();
        $statuses = collect($this->getJson("/api/v1/documents/{$doc['id']}")->json('data.versions'))->pluck('status', 'version_no')->all();
        $this->assertSame([2 => 'EFFECTIVE', 1 => 'OBSOLETE'], $statuses);
    }

    public function test_the_audit_trail_is_append_only_and_searchable(): void
    {
        $this->postJson('/api/v1/master/uoms', ['code' => 'PCS', 'name' => 'Pieces'])->assertCreated();
        $id = DB::table('audit_events')->value('id');
        foreach ([fn () => DB::table('audit_events')->where('id', $id)->update(['action' => 'edited']), fn () => DB::table('audit_events')->where('id', $id)->delete()] as $attack) {
            try {
                DB::transaction($attack);
                $this->fail('The audit trail accepted a change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame('master.uoms.created', DB::table('audit_events')->where('id', $id)->value('action'));

        $found = $this->getJson('/api/v1/audit?action=master.uoms')->assertOk()->json();
        $this->assertSame(1, $found['total']);
        $this->assertSame('PCS', $found['data'][0]['metadata']['code']);
        $this->assertSame(0, $this->getJson('/api/v1/audit?action=master.warehouses')->json('total'));
        $this->assertSame(0, $this->getJson('/api/v1/audit?action=master%25')->json('total')); // % is literal, not a wildcard
        $csv = $this->get('/api/v1/audit/export?action=master.uoms')->assertOk()->streamedContent();
        $this->assertStringContainsString('master.uoms.created', $csv);
        $this->assertStringStartsWith('waktu,aktor,aksi,subjek,metadata', $csv);
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.exported']);
        // The stock ledger is protected the same way.
        $this->assertSame(1, DB::select("select count(*) c from pg_trigger where tgname = 'stock_movements_append_only'")[0]->c);
    }

    public function test_quality_permissions_and_the_iso_map(): void
    {
        $iso = collect($this->getJson('/api/v1/quality/iso-map')->assertOk()->json('data'));
        $this->assertSame(['7.1.5', '7.5', '8.4', '8.5.2', '8.5.4', '8.6', '8.7', '9.1', '10.2'], $iso->pluck('clause')->all());

        $this->as($this->member('outbound_operator'));
        $this->getJson('/api/v1/capas')->assertOk(); // quality.view
        $this->postJson('/api/v1/capas', ['type' => 'PREVENTIVE', 'title' => 'x', 'description' => 'y', 'owner_id' => $this->manager->id, 'due_date' => now()->addDay()->toDateString()])->assertForbidden();
        $this->postJson('/api/v1/documents', ['code' => 'X', 'title' => 'x', 'category' => 'SOP', 'content' => 'x', 'change_summary' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/audit')->assertForbidden();
        $this->as($this->second); // ISO auditor: reads the trail and the quality system
        $this->getJson('/api/v1/audit')->assertOk();
        $this->postJson('/api/v1/instruments', ['code' => 'X', 'name' => 'x', 'kind' => 'SCALE', 'interval_days' => 30])->assertForbidden();
    }

    public function test_tenants_do_not_share_quality_records(): void
    {
        $capa = $this->postJson('/api/v1/capas', ['type' => 'PREVENTIVE', 'title' => 'x', 'description' => 'y', 'owner_id' => $this->manager->id, 'due_date' => now()->addDay()->toDateString()])->json('data');
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'lain', 'name' => 'Lain']);
        $u = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $other, 'user_id' => $u->id, 'role' => 'warehouse_manager', 'active' => true]);
        $this->actingAs($u)->withSession(['tenant_id' => $other]);
        $this->getJson("/api/v1/capas/{$capa['id']}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/capas')->json('data'));
        $this->assertSame(0, $this->getJson('/api/v1/audit')->json('total'));
    }
}
