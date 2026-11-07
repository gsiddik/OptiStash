<?php

namespace App\Console\Commands;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo data of the OptiStash part of the demo ecosystem (see `ecosystem:seed-demo` in OptiNexus): a warehouse with locations, the
 * products and partners, a received and put-away delivery, a picked, packed and dispatched outbound order for OptiDeli (which
 * sends the pickup request to OptiDeli), a shipment still being loaded, and one quality item of each kind. Every step goes through
 * the same controllers as the screens (the routes are run in this process without the session, with a seeding actor who holds all
 * permissions), so stock, tasks, audit and events are the real ones. Safe to run again: whatever exists is kept. Local installations only.
 */
class DemoData extends Command
{
    protected $signature = 'ecosystem:demo-data {--tenant-slug=demo-ekosistem : Slug of the tenant (the one `ecosystem:seed-demo` created)}';

    protected $description = 'Load the OptiStash part of the demo ecosystem (warehouse, stock, outbound order for OptiDeli, quality) into the demo tenant';

    private array $tenant = [];

    private User $actor;

    private object $membership;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! filter_var(env('ECOSYSTEM_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Demo data is for local installations. Set ECOSYSTEM_ALLOW_DEMO_SEED=true to allow it elsewhere.');

            return self::FAILURE;
        }
        $tenant = DB::table('tenants')->where('slug', $this->option('tenant-slug'))->first();
        if (! $tenant) {
            $this->error('Tenant not found in OptiStash yet. Run ecosystem:seed-demo in OptiNexus first and wait for the assignment event.');

            return self::FAILURE;
        }
        $this->tenant = (array) $tenant;
        // The seeding actor cannot sign in (random password, no SSO link); it only stands for the person who "did" the demo steps.
        $this->actor = User::query()->firstOrCreate(['email' => 'demo.gudang@demo-ekosistem.test'], ['name' => 'Operator Gudang Demo', 'password' => Hash::make(Str::random(48))]);
        DB::table('memberships')->updateOrInsert(['tenant_id' => $tenant->id, 'user_id' => $this->actor->id], ['role' => 'tenant_administrator', 'active' => true]);
        $this->membership = DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $this->actor->id)->first();

        if (DB::table('warehouses')->where('tenant_id', $tenant->id)->where('code', 'DEMO-WH-JKT')->exists()) {
            $this->line('OptiStash demo data already loaded; nothing to do.');

            return self::SUCCESS;
        }
        $this->load();
        $this->info('OptiStash demo data ready for '.$tenant->name.'.');

        return self::SUCCESS;
    }

    /** Runs a route of the API as the seeding actor and returns the decoded body; a failure stops the seed with the answer. */
    private function api(string $method, string $path, array $body = []): array
    {
        $uri = '/api/v1/'.ltrim($path, '/');
        $request = Request::create($uri, $method, $method === 'GET' ? $body : [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()], $method === 'GET' ? null : json_encode($body));
        app()->instance('request', $request); // rebinding installs the auth resolver, so the user is set after it
        $request->setUserResolver(fn () => $this->actor);
        $request->attributes->set('tenant', (object) $this->tenant);
        $request->attributes->set('membership_id', $this->membership->id);
        $request->attributes->set('role', $this->membership->role);
        $request->attributes->set('permissions', PermissionCatalog::templateCodes('tenant_administrator'));
        try {
            $route = app('router')->getRoutes()->match($request);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $response = app('router')->prepareResponse($request, $route->run());
        } catch (\Throwable $e) {
            $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $e);
        }
        /** @var Response $response */
        $json = json_decode((string) $response->getContent(), true) ?? [];
        if ($response->getStatusCode() >= 400) {
            $this->error("{$method} {$uri} -> {$response->getStatusCode()}: ".json_encode($json['errors'] ?? $json['message'] ?? $json));
            exit(self::FAILURE);
        }

        return $json;
    }

    private function id(string $path, array $body): string
    {
        return $this->api('POST', $path, $body)['data']['id'];
    }

    private function load(): void
    {
        $day = fn (int $plus) => now()->addDays($plus)->toDateString();
        $wh = $this->id('master/warehouses', ['code' => 'DEMO-WH-JKT', 'name' => 'Gudang Utama Jakarta', 'granularity' => 'BIN', 'city' => 'Jakarta', 'address' => 'Jl. Gudang Utama No. 1, Jakarta']);
        $pcs = $this->id('master/uoms', ['code' => 'PCS', 'name' => 'Pieces']);
        $box = $this->id('master/uoms', ['code' => 'BOX', 'name' => 'Karton']);
        $rice = $this->id('master/products', ['code' => 'DEMO-BERAS', 'name' => 'Beras Premium 5 kg', 'base_uom_id' => $pcs, 'rotation_policy' => 'FIFO', 'track_lot' => false, 'track_expiry' => false, 'temperature_class' => 'AMBIENT', 'weight_g' => 5000, 'barcode' => 'DEMO-BC-BERAS']);
        $milk = $this->id('master/products', ['code' => 'DEMO-SUSU', 'name' => 'Susu UHT 1 L', 'base_uom_id' => $box, 'rotation_policy' => 'FEFO', 'track_lot' => true, 'track_expiry' => true, 'shelf_life_days' => 365, 'min_remaining_shelf_life_days' => 30, 'temperature_class' => 'AMBIENT', 'barcode' => 'DEMO-BC-SUSU']);
        $supplier = $this->id('master/partners', ['code' => 'DEMO-SUP1', 'name' => 'PT Pemasok Pangan Demo', 'type' => 'SUPPLIER', 'email' => 'pemasok@example.test']);
        $customer = $this->id('master/partners', ['code' => 'DEMO-CUS1', 'name' => 'PT Nusantara Retail Demo', 'type' => 'CUSTOMER']);
        $dock = $this->id('master/docks', ['code' => 'DEMO-D1', 'name' => 'Dock 1', 'warehouse_id' => $wh]);
        $zone = $this->id("warehouses/{$wh}/locations", ['code' => 'Z1', 'name' => 'Zona 1']);
        $aisle = $this->id("warehouses/{$wh}/locations", ['parent_id' => $zone, 'code' => 'Z1-A1', 'name' => 'Lorong 1']);
        $rack = $this->id("warehouses/{$wh}/locations", ['parent_id' => $aisle, 'code' => 'Z1-A1-R1', 'name' => 'Rak 1']);
        $shelf = $this->id("warehouses/{$wh}/locations", ['parent_id' => $rack, 'code' => 'Z1-A1-R1-S1', 'name' => 'Tingkat 1']);
        $this->api('POST', "warehouses/{$wh}/locations/generate", ['parent_id' => $shelf, 'prefix' => 'B', 'count' => 8]);
        $this->line('master data and warehouse structure ok');

        // Inbound: ASN, dock booking, gate in, receive, put away.
        $asn = $this->api('POST', 'asns', ['supplier_id' => $supplier, 'warehouse_id' => $wh, 'expected_date' => $day(1), 'reference' => 'DEMO-PO-1', 'lines' => [['product_id' => $rice, 'expected_qty' => 200], ['product_id' => $milk, 'expected_qty' => 96]]])['data'];
        $this->api('POST', "asns/{$asn['id']}/submit");
        $this->api('POST', "asns/{$asn['id']}/confirm");
        $slot = fn (int $h) => now()->addDay()->setTime($h, 0)->utc()->toIso8601String();
        $booking = $this->api('POST', 'dock-bookings', ['dock_id' => $dock, 'asn_id' => $asn['id'], 'slot_start' => $slot(1), 'slot_end' => $slot(3)])['data'];
        $this->api('POST', "dock-bookings/{$booking['id']}/gate-in", ['truck_plate' => 'B 8888 PGN']);
        $lines = collect($this->api('GET', "asns/{$asn['id']}")['data']['lines']);
        $riceLine = $lines->firstWhere('product_id', $rice)['id'];
        $milkLine = $lines->firstWhere('product_id', $milk)['id'];
        $this->api('POST', "asn-lines/{$riceLine}/receive", ['quantity' => 120]);
        $this->api('POST', "asn-lines/{$riceLine}/receive", ['quantity' => 80]);
        $this->api('POST', "asn-lines/{$milkLine}/receive", ['quantity' => 96, 'lot_no' => 'L-DEMO-1', 'expiry_date' => $day(200)]);
        foreach ($this->api('GET', 'putaway-tasks')['data'] as $task) {
            if (($task['status'] ?? '') !== 'DONE') {
                $this->api('POST', "putaway-tasks/{$task['id']}/complete", []);
            }
        }
        $this->api('POST', "asns/{$asn['id']}/complete", []);
        $this->line('inbound ok');

        // Outbound for OptiDeli: allocate, wave, pick, pack, shipment, dispatch (this sends the pickup request).
        $this->api('PUT', 'integration/transport', ['mode' => 'OPTIDELI']);
        $order = $this->api('POST', 'outbound-orders', ['customer_id' => $customer, 'warehouse_id' => $wh, 'required_date' => $day(1), 'reference' => 'DEMO-SO-1', 'ship_to' => 'Jl. Raya Darmo No. 20, Surabaya', 'lines' => [['product_id' => $rice, 'ordered_qty' => 60], ['product_id' => $milk, 'ordered_qty' => 24]]])['data'];
        $this->api('POST', "outbound-orders/{$order['id']}/release");
        $this->api('POST', "outbound-orders/{$order['id']}/allocate");
        $wave = $this->api('POST', 'waves', ['type' => 'WAVE', 'order_ids' => [$order['id']]])['data'];
        foreach ($this->api('GET', "waves/{$wave['id']}")['data']['tasks'] ?? [] as $task) {
            $this->api('POST', "pick-tasks/{$task['id']}/pick", ['lpn' => $task['lpn'], 'quantity' => $task['quantity']]);
        }
        $package = $this->api('POST', "outbound-orders/{$order['id']}/packages")['data'];
        $this->api('POST', "packages/{$package['id']}/items", ['scan' => 'DEMO-BC-BERAS', 'quantity' => 60]);
        $this->api('POST', "packages/{$package['id']}/items", ['scan' => 'DEMO-BC-SUSU', 'quantity' => 24]);
        $this->api('POST', "packages/{$package['id']}/close");
        $this->api('POST', "outbound-orders/{$order['id']}/pack-complete", []);
        $shipment = $this->api('POST', 'shipments', ['warehouse_id' => $wh, 'driver_name' => 'Joko', 'truck_plate' => 'B 9001 DMO'])['data'];
        $this->api('POST', "shipments/{$shipment['id']}/orders", ['order_id' => $order['id']]);
        $this->api('POST', "shipments/{$shipment['id']}/dispatch");
        $this->api('POST', 'shipments', ['warehouse_id' => $wh]); // a second one still being loaded
        $this->line('outbound ok (pickup request sent to OptiDeli)');

        // Quality, inventory control, integration.
        $this->api('POST', 'capas', ['type' => 'PREVENTIVE', 'title' => 'Latih operator forklift', 'description' => 'Pelatihan keselamatan', 'owner_id' => $this->actor->id, 'due_date' => $day(14)]);
        $this->api('POST', 'instruments', ['code' => 'DEMO-TH-01', 'name' => 'Termometer dock', 'kind' => 'THERMOMETER', 'interval_days' => 180]);
        $this->api('POST', 'documents', ['code' => 'DEMO-SOP-001', 'title' => 'Penerimaan barang', 'category' => 'SOP', 'content' => 'Langkah 1. Periksa segel.', 'change_summary' => 'Versi awal']);
        $this->api('POST', 'stocktakes', ['warehouse_id' => $wh]);
        $this->api('POST', 'iot/gateways', ['name' => 'Gateway Demo', 'protocol' => 'REST']);
        $this->line('quality and integration ok');
    }
}
