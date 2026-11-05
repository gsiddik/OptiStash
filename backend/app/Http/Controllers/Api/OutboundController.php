<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Outbound\OrderService;
use App\Domain\Outbound\PickingService;
use App\Domain\Outbound\ShippingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Outbound (Module 4): orders and allocation, waves and picking, packing, shipments and dispatch. Permissions are checked per action. */
class OutboundController extends Controller
{
    private function orders(Request $r): OrderService
    {
        return new OrderService(self::storeFor($r));
    }

    private function ship(Request $r): ShippingService
    {
        return new ShippingService(self::storeFor($r));
    }

    private function can(Request $r, string $permission, string $message): void
    {
        Access::authorize($r, $permission, $message);
    }

    public function index(Request $r)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat pesanan keluar.');

        return response()->json($this->orders($r)->list($r->query('status'), max(1, (int) $r->query('page', 1))));
    }

    public function show(Request $r, string $id)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat pesanan keluar.');

        return response()->json(['data' => $this->orders($r)->one($id)]);
    }

    public function store(Request $r)
    {
        $this->can($r, 'outbound.manage', 'Akun Anda tidak boleh membuat pesanan keluar.');
        $d = $r->validate([
            'customer_id' => 'required|uuid', 'warehouse_id' => 'required|uuid', 'required_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'reference' => 'nullable|string|max:120', 'ship_to' => 'required|string|max:1000', 'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:200', 'lines.*.product_id' => 'required|uuid', 'lines.*.ordered_qty' => 'required|integer|min:1|max:10000000',
        ]);

        return response()->json(['data' => $this->orders($r)->create($d)], 201);
    }

    public function release(Request $r, string $id)
    {
        $this->can($r, 'outbound.manage', 'Akun Anda tidak boleh merilis pesanan.');

        return response()->json(['data' => $this->orders($r)->release($id)]);
    }

    public function allocate(Request $r, string $id)
    {
        $this->can($r, 'outbound.manage', 'Akun Anda tidak boleh mengalokasikan stok.');

        return response()->json(['data' => $this->orders($r)->allocate($id)]);
    }

    public function cancel(Request $r, string $id)
    {
        $this->can($r, 'outbound.manage', 'Akun Anda tidak boleh membatalkan pesanan.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->orders($r)->cancel($id, $d['reason'])]);
    }

    public function waves(Request $r)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat wave.');

        return response()->json(['data' => (new PickingService(self::storeFor($r)))->waves($r->query('status'))]);
    }

    public function wave(Request $r, string $id)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat wave.');

        return response()->json(['data' => (new PickingService(self::storeFor($r)))->wave($id)]);
    }

    public function createWave(Request $r)
    {
        $this->can($r, 'outbound.manage', 'Akun Anda tidak boleh membuat wave.');
        $d = $r->validate(['type' => 'required|string|in:'.implode(',', PickingService::TYPES), 'order_ids' => 'required|array|min:1|max:100', 'order_ids.*' => 'uuid']);

        return response()->json(['data' => (new PickingService(self::storeFor($r)))->createWave($d['type'], array_values($d['order_ids']))], 201);
    }

    public function pick(Request $r, string $task)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh melakukan picking.');
        $d = $r->validate(['lpn' => 'required|string|max:40', 'quantity' => 'required|integer|min:0|max:100000000', 'short_reason' => 'nullable|string|max:500']);

        return response()->json(['data' => (new PickingService(self::storeFor($r)))->pick($task, $d['lpn'], $d['quantity'], $d['short_reason'] ?? null)]);
    }

    public function openPackage(Request $r, string $id)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh melakukan packing.');

        return response()->json(['data' => $this->ship($r)->openPackage($id)], 201);
    }

    public function packItem(Request $r, string $package)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh melakukan packing.');
        $d = $r->validate(['scan' => 'required|string|max:60', 'quantity' => 'required|integer|min:1|max:100000000']);

        return response()->json(['data' => $this->ship($r)->addItem($package, $d['scan'], $d['quantity'])]);
    }

    public function closePackage(Request $r, string $package)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh melakukan packing.');

        return response()->json(['data' => $this->ship($r)->closePackage($package)]);
    }

    public function completePacking(Request $r, string $id)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh melakukan packing.');
        $d = $r->validate(['partial_reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->ship($r)->completePacking($id, $d['partial_reason'] ?? null, Access::can($r, 'wave.approve'))]);
    }

    public function shipments(Request $r)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat pengiriman.');

        return response()->json(['data' => $this->ship($r)->shipments($r->query('status'))]);
    }

    public function shipment(Request $r, string $id)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat pengiriman.');

        return response()->json(['data' => $this->ship($r)->shipment($id)]);
    }

    public function manifest(Request $r, string $id)
    {
        $this->can($r, 'outbound.view', 'Akun Anda tidak boleh melihat manifes.');

        return response()->json(['data' => $this->ship($r)->manifest($id)]);
    }

    public function createShipment(Request $r)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh membuat pengiriman.');
        $d = $r->validate(['warehouse_id' => 'required|uuid', 'carrier_id' => 'nullable|uuid', 'truck_plate' => 'nullable|string|max:30', 'driver_name' => 'nullable|string|max:150']);

        return response()->json(['data' => $this->ship($r)->createShipment($d['warehouse_id'], $d['carrier_id'] ?? null, $d['truck_plate'] ?? null, $d['driver_name'] ?? null)], 201);
    }

    public function addShipmentOrder(Request $r, string $id)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh memuat pesanan.');
        $d = $r->validate(['order_id' => 'required|uuid']);

        return response()->json(['data' => $this->ship($r)->addOrder($id, $d['order_id'])]);
    }

    public function removeShipmentOrder(Request $r, string $id, string $order)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh memuat pesanan.');

        return response()->json(['data' => $this->ship($r)->removeOrder($id, $order)]);
    }

    public function dispatch(Request $r, string $id)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh mengirimkan barang.');

        return response()->json(['data' => $this->ship($r)->dispatch($id)]);
    }

    public function cancelShipment(Request $r, string $id)
    {
        $this->can($r, 'outbound.execute', 'Akun Anda tidak boleh membatalkan pengiriman.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->ship($r)->cancelShipment($id, $d['reason'])]);
    }
}
