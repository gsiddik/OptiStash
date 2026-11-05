<?php

use App\Http\Controllers\Api\InboundController as I;
use Illuminate\Support\Facades\Route;

// Module 2 (internal): permission checks live in the controller.
Route::get('asns', [I::class, 'asns']);
Route::post('asns', [I::class, 'createAsn']);
Route::get('asns/{id}', [I::class, 'asn'])->whereUuid('id');
Route::put('asns/{id}', [I::class, 'updateAsn'])->whereUuid('id');
Route::post('asns/{id}/submit', [I::class, 'submitAsn'])->whereUuid('id');
Route::post('asns/{id}/confirm', [I::class, 'confirmAsn'])->whereUuid('id');
Route::post('asns/{id}/cancel', [I::class, 'cancelAsn'])->whereUuid('id');
Route::post('asns/{id}/complete', [I::class, 'completeAsn'])->whereUuid('id');
Route::get('dock-bookings', [I::class, 'bookings']);
Route::post('dock-bookings', [I::class, 'book']);
Route::post('dock-bookings/{id}/cancel', [I::class, 'cancelBooking'])->whereUuid('id');
Route::post('dock-bookings/{id}/gate-in', [I::class, 'gateIn'])->whereUuid('id');
Route::post('dock-bookings/{id}/gate-out', [I::class, 'gateOut'])->whereUuid('id');
Route::post('asn-lines/{line}/receive', [I::class, 'receive'])->whereUuid('line');
Route::get('stock-units', [I::class, 'units']);
Route::get('stock-units/{id}/label', [I::class, 'label'])->whereUuid('id');
Route::post('stock-units/{id}/decision', [I::class, 'decide'])->whereUuid('id');
Route::get('putaway-tasks', [I::class, 'putawayTasks']);
Route::get('putaway-tasks/{task}/locations', [I::class, 'taskLocations'])->whereUuid('task');
Route::post('putaway-tasks/{task}/complete', [I::class, 'putaway'])->whereUuid('task');
Route::get('nonconformances', [I::class, 'nonconformances']);
