<?php

use App\Domain\Master\MasterRegistry;
use App\Http\Controllers\Api\MasterController as M;
use App\Http\Controllers\Api\MasterImportController as I;
use Illuminate\Support\Facades\Route;

$resources = implode('|', array_keys(MasterRegistry::all())) ?: 'none-yet';

Route::get('master/schema', [M::class, 'schema']);
Route::get('master/imports/{id}', [I::class, 'show'])->whereUuid('id');
Route::get('master/imports/{id}/rows', [I::class, 'rows'])->whereUuid('id');
Route::get('master/imports/{id}/errors', [I::class, 'errors'])->whereUuid('id');
Route::post('master/imports/{id}/commit', [I::class, 'commit'])->whereUuid('id');
Route::post('master/imports/{id}/discard', [I::class, 'discard'])->whereUuid('id');
Route::get('master/{resource}/template', [I::class, 'template'])->where('resource', $resources);
Route::get('master/{resource}/export', [I::class, 'export'])->where('resource', $resources);
Route::post('master/{resource}/imports', [I::class, 'store'])->where('resource', $resources);
Route::get('master/{resource}', [M::class, 'index'])->where('resource', $resources);
Route::get('master/{resource}/options', [M::class, 'options'])->where('resource', $resources);
Route::post('master/{resource}', [M::class, 'store'])->where('resource', $resources);
Route::put('master/{resource}/{id}', [M::class, 'update'])->where('resource', $resources)->whereUuid('id');
Route::post('master/{resource}/{id}/status', [M::class, 'status'])->where('resource', $resources)->whereUuid('id');
