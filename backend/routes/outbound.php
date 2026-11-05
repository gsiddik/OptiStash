<?php

use App\Http\Controllers\Api\OutboundController as O;
use Illuminate\Support\Facades\Route;

// Module 4: outbound orders, waves and picking, packing, shipments. Permission checks live in the controller.
Route::get('outbound-orders', [O::class, 'index']);
Route::post('outbound-orders', [O::class, 'store']);
Route::get('outbound-orders/{id}', [O::class, 'show'])->whereUuid('id');
Route::post('outbound-orders/{id}/release', [O::class, 'release'])->whereUuid('id');
Route::post('outbound-orders/{id}/allocate', [O::class, 'allocate'])->whereUuid('id');
Route::post('outbound-orders/{id}/cancel', [O::class, 'cancel'])->whereUuid('id');
Route::post('outbound-orders/{id}/packages', [O::class, 'openPackage'])->whereUuid('id');
Route::post('outbound-orders/{id}/pack-complete', [O::class, 'completePacking'])->whereUuid('id');
Route::post('packages/{package}/items', [O::class, 'packItem'])->whereUuid('package');
Route::post('packages/{package}/close', [O::class, 'closePackage'])->whereUuid('package');
Route::get('waves', [O::class, 'waves']);
Route::post('waves', [O::class, 'createWave']);
Route::get('waves/{id}', [O::class, 'wave'])->whereUuid('id');
Route::post('pick-tasks/{task}/pick', [O::class, 'pick'])->whereUuid('task');
Route::get('shipments', [O::class, 'shipments']);
Route::post('shipments', [O::class, 'createShipment']);
Route::get('shipments/{id}', [O::class, 'shipment'])->whereUuid('id');
Route::get('shipments/{id}/manifest', [O::class, 'manifest'])->whereUuid('id');
Route::post('shipments/{id}/orders', [O::class, 'addShipmentOrder'])->whereUuid('id');
Route::delete('shipments/{id}/orders/{order}', [O::class, 'removeShipmentOrder'])->whereUuid('id')->whereUuid('order');
Route::post('shipments/{id}/dispatch', [O::class, 'dispatch'])->whereUuid('id');
Route::post('shipments/{id}/cancel', [O::class, 'cancelShipment'])->whereUuid('id');
