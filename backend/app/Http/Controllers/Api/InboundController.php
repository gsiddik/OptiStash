<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Inbound\AsnService;
use App\Domain\Inbound\DockService;
use App\Domain\Inbound\ReceivingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Inbound & QC for warehouse staff: ASN, dock bookings and gate, receiving, quality decisions and putaway. */
class InboundController extends Controller
{
    private const ASN_RULES = [
        'supplier_id' => 'required|uuid', 'warehouse_id' => 'required|uuid', 'expected_date' => 'required|date_format:Y-m-d',
        'reference' => 'nullable|string|max:120', 'truck_plate' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:1000',
        'lines' => 'required|array|min:1|max:200', 'lines.*.product_id' => 'required|uuid', 'lines.*.expected_qty' => 'required|integer|min:1|max:10000000',
    ];

    private function view(Request $r): void
    {
        Access::authorize($r, 'inbound.view', 'Akun Anda tidak boleh melihat data inbound.');
    }

    public function asns(Request $r)
    {
        $this->view($r);

        return response()->json((new AsnService(self::storeFor($r)))->list($r->query('status'), $r->query('supplier_id'), max(1, (int) $r->query('page', 1))));
    }

    public function asn(Request $r, string $id)
    {
        $this->view($r);

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->one($id)]);
    }

    public function createAsn(Request $r)
    {
        Access::authorize($r, 'asn.manage', 'Akun Anda tidak boleh membuat ASN.');

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->create($r->validate(self::ASN_RULES), 'INTERNAL')], 201);
    }

    public function updateAsn(Request $r, string $id)
    {
        Access::authorize($r, 'asn.manage', 'Akun Anda tidak boleh mengubah ASN.');

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->update($id, $r->validate(self::ASN_RULES))]);
    }

    public function submitAsn(Request $r, string $id)
    {
        Access::authorize($r, 'asn.manage', 'Akun Anda tidak boleh mengajukan ASN.');

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->submit($id)]);
    }

    public function confirmAsn(Request $r, string $id)
    {
        Access::authorize($r, 'asn.approve', 'Akun Anda tidak boleh mengonfirmasi ASN.');

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->confirm($id)]);
    }

    public function cancelAsn(Request $r, string $id)
    {
        Access::authorize($r, 'asn.approve', 'Akun Anda tidak boleh membatalkan ASN.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->cancel($id, $d['reason'])]);
    }

    public function completeAsn(Request $r, string $id)
    {
        Access::authorize($r, 'receiving.execute', 'Akun Anda tidak boleh menutup penerimaan.');
        $d = $r->validate(['reason' => 'nullable|string|max:500']);

        return response()->json(['data' => (new AsnService(self::storeFor($r)))->complete($id, $d['reason'] ?? null)]);
    }

    public function bookings(Request $r)
    {
        $this->view($r);

        return response()->json(['data' => (new DockService(self::storeFor($r)))->list($r->query('from'), $r->query('status'))]);
    }

    public function book(Request $r)
    {
        Access::authorize($r, 'dock.manage', 'Akun Anda tidak boleh mengatur jadwal dock.');
        $d = $r->validate(['dock_id' => 'required|uuid', 'asn_id' => 'required|uuid', 'slot_start' => 'required|date', 'slot_end' => 'required|date', 'truck_plate' => 'nullable|string|max:30']);

        return response()->json(['data' => (new DockService(self::storeFor($r)))->book($d['dock_id'], $d['asn_id'], $d['slot_start'], $d['slot_end'], $d['truck_plate'] ?? null)], 201);
    }

    public function cancelBooking(Request $r, string $id)
    {
        Access::authorize($r, 'dock.manage', 'Akun Anda tidak boleh mengatur jadwal dock.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => (new DockService(self::storeFor($r)))->cancel($id, $d['reason'])]);
    }

    public function gateIn(Request $r, string $id)
    {
        Access::authorize($r, 'dock.manage', 'Akun Anda tidak boleh mencatat gate-in.');
        $d = $r->validate(['truck_plate' => 'nullable|string|max:30']);

        return response()->json(['data' => (new DockService(self::storeFor($r)))->gateIn($id, $d['truck_plate'] ?? null)]);
    }

    public function gateOut(Request $r, string $id)
    {
        Access::authorize($r, 'dock.manage', 'Akun Anda tidak boleh mencatat gate-out.');

        return response()->json(['data' => (new DockService(self::storeFor($r)))->gateOut($id)]);
    }

    public function receive(Request $r, string $line)
    {
        Access::authorize($r, 'receiving.execute', 'Akun Anda tidak boleh menerima barang.');
        $d = $r->validate([
            'quantity' => 'required|integer|min:1|max:10000000', 'lot_no' => 'nullable|string|max:60', 'expiry_date' => 'nullable|date_format:Y-m-d',
            'nonconforming' => 'nullable|boolean', 'category' => 'nullable|string|max:20', 'description' => 'nullable|string|max:1000', 'instrument_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->receive($line, $d['quantity'], $d)], 201);
    }

    public function units(Request $r)
    {
        $this->view($r);

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->units((string) $r->query('status', 'QUARANTINE'))]);
    }

    public function label(Request $r, string $id)
    {
        Access::authorize($r, 'receiving.execute', 'Akun Anda tidak boleh mencetak label.');

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->label($id)]);
    }

    public function decide(Request $r, string $id)
    {
        Access::authorize($r, 'qc.inspect', 'Akun Anda tidak boleh mengambil keputusan mutu.');
        $d = $r->validate(['decision' => 'required|string|in:QUARANTINE,RELEASE,REJECT', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->decide($id, $d['decision'], $d['reason'])]);
    }

    public function putawayTasks(Request $r)
    {
        $this->view($r);

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->openPutawayTasks()]);
    }

    public function putaway(Request $r, string $task)
    {
        Access::authorize($r, 'putaway.execute', 'Akun Anda tidak boleh melakukan putaway.');
        $d = $r->validate(['location_id' => 'nullable|uuid', 'override_reason' => 'nullable|string|max:500']);

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->putaway($task, $d['location_id'] ?? null, $d['override_reason'] ?? null)]);
    }

    public function taskLocations(Request $r, string $task)
    {
        Access::authorize($r, 'putaway.execute', 'Akun Anda tidak boleh melakukan putaway.');

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->taskLocations($task)]);
    }

    public function nonconformances(Request $r)
    {
        Access::authorize($r, 'quality.view', 'Akun Anda tidak boleh melihat data mutu.');

        return response()->json(['data' => (new ReceivingService(self::storeFor($r)))->nonconformances($r->query('status'))]);
    }
}
