<?php

use App\Http\Controllers\Api\IotController as I;
use Illuminate\Support\Facades\Route;

// Module 6: orchestrator status and control, IoT gateways, devices and alerts. Permission checks live in the controller.
Route::get('integration/status', [I::class, 'status']);
Route::put('integration/standalone', [I::class, 'setStandalone']);
Route::post('integration/sync', [I::class, 'sync']);
Route::get('iot/gateways', [I::class, 'gateways']);
Route::post('iot/gateways', [I::class, 'createGateway']);
Route::post('iot/gateways/{id}/rotate-token', [I::class, 'rotateGateway'])->whereUuid('id');
Route::post('iot/gateways/{id}/status', [I::class, 'gatewayStatus'])->whereUuid('id');
Route::get('iot/devices', [I::class, 'devices']);
Route::post('iot/devices', [I::class, 'createDevice']);
Route::put('iot/devices/{id}', [I::class, 'updateDevice'])->whereUuid('id');
Route::post('iot/devices/{id}/status', [I::class, 'deviceStatus'])->whereUuid('id');
Route::get('iot/devices/{id}/readings', [I::class, 'readings'])->whereUuid('id');
Route::get('iot/alerts', [I::class, 'alerts']);
Route::post('iot/alerts/{id}/acknowledge', [I::class, 'acknowledge'])->whereUuid('id');
Route::post('iot/alerts/{id}/close', [I::class, 'closeAlert'])->whereUuid('id');
