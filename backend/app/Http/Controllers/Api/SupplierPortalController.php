<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inbound\AsnService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The supplier portal: a supplier's own ASNs only. The supplier comes from the membership, never from the request. */
class SupplierPortalController extends Controller
{
    private function supplierId(Request $r): string
    {
        return (string) $r->attributes->get('supplier_id');
    }

    /** The supplier's own ASN, or 404 for anyone else's (a supplier cannot tell whether another supplier's ASN exists). */
    private function own(Request $r, string $id): AsnService
    {
        $service = new AsnService(self::storeFor($r));
        abort_unless(self::storeFor($r)->query('asns')->where('id', $id)->where('supplier_id', $this->supplierId($r))->exists(), 404);

        return $service;
    }

    public function products(Request $r)
    {
        return response()->json(['data' => self::storeFor($r)->query('products')->where('active', true)->orderBy('code')->get(['id', 'code', 'name'])]);
    }

    public function warehouses(Request $r)
    {
        return response()->json(['data' => self::storeFor($r)->query('warehouses')->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'city'])]);
    }

    public function asns(Request $r)
    {
        return response()->json((new AsnService(self::storeFor($r)))->list($r->query('status'), $this->supplierId($r), max(1, (int) $r->query('page', 1))));
    }

    public function asn(Request $r, string $id)
    {
        return response()->json(['data' => $this->own($r, $id)->one($id)]);
    }

    public function create(Request $r)
    {
        $d = $r->validate([
            'warehouse_id' => 'required|uuid', 'expected_date' => 'required|date_format:Y-m-d', 'reference' => 'nullable|string|max:120',
            'truck_plate' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:200', 'lines.*.product_id' => 'required|uuid', 'lines.*.expected_qty' => 'required|integer|min:1|max:10000000',
        ]);
        $d['supplier_id'] = $this->supplierId($r);

        return response()->json(['data' => DB::transaction(fn () => (new AsnService(self::storeFor($r)))->create($d, 'PORTAL'))], 201);
    }

    public function update(Request $r, string $id)
    {
        $service = $this->own($r, $id);
        $d = $r->validate([
            'warehouse_id' => 'required|uuid', 'expected_date' => 'required|date_format:Y-m-d', 'reference' => 'nullable|string|max:120',
            'truck_plate' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:200', 'lines.*.product_id' => 'required|uuid', 'lines.*.expected_qty' => 'required|integer|min:1|max:10000000',
        ]);
        $d['supplier_id'] = $this->supplierId($r);

        return response()->json(['data' => $service->update($id, $d)]);
    }

    public function submit(Request $r, string $id)
    {
        return response()->json(['data' => $this->own($r, $id)->submit($id)]);
    }

    public function cancel(Request $r, string $id)
    {
        $service = $this->own($r, $id);
        $d = $r->validate(['reason' => 'required|string|max:500']);
        $asn = self::storeFor($r)->find('asns', $id);
        abort_unless(in_array($asn->status, ['DRAFT', 'SUBMITTED'], true), 422, 'ASN yang sudah dikonfirmasi hanya dapat dibatalkan oleh gudang.');

        return response()->json(['data' => $service->cancel($id, $d['reason'])]);
    }
}
