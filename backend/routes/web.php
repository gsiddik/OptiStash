<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OptinexusEventController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\SsoController;
use App\Http\Middleware\InternalAccess;
use App\Http\Middleware\SupplierAccess;
use App\Http\Middleware\PlatformContext;
use Illuminate\Support\Facades\Route;

Route::get('/api/v1/csrf', fn () => response()->json(['token' => csrf_token()]));
Route::get('/api/v1/health', fn () => response()->json(['status' => 'ok', 'service' => 'optistash']));
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/api/v1/auth/external-login', [AuthController::class, 'externalLogin'])->middleware('throttle:login');
Route::get('/api/v1/auth/sso/status', [SsoController::class, 'status']);
Route::get('/api/v1/auth/sso/redirect', [SsoController::class, 'redirect'])->middleware('throttle:30,1');
Route::get('/api/v1/auth/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:30,1');
Route::post('/api/v1/integration/optinexus/backchannel-logout', [SsoController::class, 'backchannelLogout'])->middleware('throttle:120,1');
Route::post('/api/v1/integration/optinexus/events', [OptinexusEventController::class, 'receive'])->middleware('throttle:600,1');
Route::post('/api/v1/platform/auth/login', [PlatformController::class, 'login'])->middleware('throttle:login');
Route::middleware(['auth', PlatformContext::class, 'throttle:120,1'])->prefix('api/v1/platform')->group(function () {
    Route::get('auth/me', [PlatformController::class, 'me']);
    Route::get('tenants', [PlatformController::class, 'tenants']);
    Route::post('tenants', [PlatformController::class, 'createTenant']);
    Route::post('tenants/{id}/status', [PlatformController::class, 'tenantStatus'])->whereUuid('id');
    Route::post('tenants/{id}/administrators', [PlatformController::class, 'createTenantAdmin'])->whereUuid('id');
    Route::post('tenants/{id}/optinexus', [PlatformController::class, 'linkOptinexus'])->whereUuid('id');
    Route::get('audit', [PlatformController::class, 'audit']);
});
Route::middleware(['auth', 'tenant', 'throttle:120,1'])->prefix('api/v1')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::middleware(InternalAccess::class)->group(function () {
        require __DIR__.'/master.php';
        require __DIR__.'/access.php';
        require __DIR__.'/warehouse.php';
        require __DIR__.'/inbound.php';
        require __DIR__.'/inventory.php';
        require __DIR__.'/outbound.php';
        require __DIR__.'/quality.php';
    });
    Route::middleware(SupplierAccess::class)->group(function () {
        require __DIR__.'/supplier.php';
    });
});
