<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Integration\Orchestrator;
use App\Domain\Iot\IotService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** IoT gateway, devices, telemetry alerts and the integration orchestrator (Module 6), plus the machine ingestion endpoint. */
class IotController extends Controller
{
    private function view(Request $r): IotService
    {
        Access::authorize($r, 'integration.view', 'Akun Anda tidak boleh melihat integrasi.');

        return new IotService(self::storeFor($r));
    }

    private function manage(Request $r): IotService
    {
        Access::authorize($r, 'integration.manage', 'Akun Anda tidak boleh mengelola integrasi.');

        return new IotService(self::storeFor($r));
    }

    public function status(Request $r)
    {
        $this->view($r);

        return response()->json(['data' => Orchestrator::status($r->attributes->get('tenant')->id)]);
    }

    public function setStandalone(Request $r)
    {
        $this->manage($r);
        $d = $r->validate(['standalone' => 'required|boolean']);

        return response()->json(['data' => Orchestrator::setStandalone(self::storeFor($r), (bool) $d['standalone'])]);
    }

    public function sync(Request $r)
    {
        $this->manage($r);
        $store = self::storeFor($r);
        $result = Orchestrator::deliver($store->tenantId, true);
        $store->event('integration.force_sync', $store->tenantId, $result);

        return response()->json(['data' => $result + ['status' => Orchestrator::status($store->tenantId)]]);
    }

    public function gateways(Request $r)
    {
        return response()->json(['data' => $this->view($r)->gateways()]);
    }

    public function createGateway(Request $r)
    {
        $service = $this->manage($r);
        $d = $r->validate(['name' => 'required|string|max:150', 'protocol' => 'required|string|in:REST,MQTT_BRIDGE']);

        return response()->json(['data' => $service->createGateway($d['name'], $d['protocol'])], 201);
    }

    public function rotateGateway(Request $r, string $id)
    {
        return response()->json(['data' => $this->manage($r)->rotateToken($id)]);
    }

    public function gatewayStatus(Request $r, string $id)
    {
        $service = $this->manage($r);
        $d = $r->validate(['active' => 'required|boolean']);
        $service->setGatewayActive($id, (bool) $d['active']);

        return response()->noContent();
    }

    private const DEVICE_RULES = [
        'name' => 'required|string|max:200', 'gateway_id' => 'nullable|uuid', 'mqtt_topic' => 'nullable|string|max:200', 'metric' => 'required|string|max:12', 'unit' => 'nullable|string|max:12',
        'min_value' => 'nullable|numeric|between:-100000000,100000000', 'max_value' => 'nullable|numeric|between:-100000000,100000000', 'binding_type' => 'nullable|string|in:NONE,LOCATION,STOCK_UNIT',
        'binding_id' => 'nullable|uuid', 'auto_lock' => 'nullable|boolean', 'stale_after_minutes' => 'nullable|integer|min:1|max:10080',
    ];

    public function devices(Request $r)
    {
        return response()->json(['data' => $this->view($r)->devices()]);
    }

    public function createDevice(Request $r)
    {
        $service = $this->manage($r);
        $d = $r->validate(self::DEVICE_RULES + ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'], 'external_id' => 'required|string|max:100']);

        return response()->json(['data' => $service->saveDevice(null, $d)], 201);
    }

    public function updateDevice(Request $r, string $id)
    {
        $service = $this->manage($r);

        return response()->json(['data' => $service->saveDevice($id, $r->validate(self::DEVICE_RULES))]);
    }

    public function deviceStatus(Request $r, string $id)
    {
        $service = $this->manage($r);
        $d = $r->validate(['active' => 'required|boolean']);
        $service->setDeviceActive($id, (bool) $d['active']);

        return response()->noContent();
    }

    public function readings(Request $r, string $id)
    {
        return response()->json(['data' => $this->view($r)->readings($id, (int) $r->query('hours', 24))]);
    }

    public function alerts(Request $r)
    {
        return response()->json(['data' => $this->view($r)->alerts($r->query('status'))]);
    }

    public function acknowledge(Request $r, string $id)
    {
        $service = $this->manage($r);
        $d = $r->validate(['note' => 'required|string|max:500']);

        return response()->json(['data' => $service->acknowledge($id, $d['note'])]);
    }

    public function closeAlert(Request $r, string $id)
    {
        $service = $this->manage($r);
        $d = $r->validate(['note' => 'required|string|max:500']);

        return response()->json(['data' => $service->close($id, $d['note'])]);
    }

    /** Machine endpoint: `POST /api/v1/integration/iot/readings`, bearer token of a gateway. No session, no CSRF. */
    public function ingest(Request $r)
    {
        $gateway = IotService::gatewayByToken((string) $r->bearerToken());
        if (! $gateway) {
            return response()->json(['message' => 'Token gateway tidak valid.', 'code' => 'IOT_TOKEN_INVALID'], 401);
        }
        $d = $r->validate(['readings' => 'required|array|min:1', 'readings.*.device' => 'nullable|string|max:100', 'readings.*.topic' => 'nullable|string|max:200', 'readings.*.value' => 'required', 'readings.*.at' => 'nullable|string|max:40']);

        return response()->json(['data' => IotService::ingest($gateway, $d['readings'])], 202);
    }
}
