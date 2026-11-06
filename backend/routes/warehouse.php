<?php

use App\Http\Controllers\Api\WarehouseController as W;
use Illuminate\Support\Facades\Route;

// Module 1: storage hierarchy, supplier portal accounts, transport integration. Permission checks live in the controller.
Route::get('warehouses/{warehouse}/locations', [W::class, 'tree'])->whereUuid('warehouse');
Route::post('warehouses/{warehouse}/locations', [W::class, 'createNode'])->whereUuid('warehouse');
Route::post('warehouses/{warehouse}/locations/generate', [W::class, 'generateNodes'])->whereUuid('warehouse');
Route::put('locations/{node}', [W::class, 'updateNode'])->whereUuid('node');
Route::post('locations/{node}/block', [W::class, 'blockNode'])->whereUuid('node');
Route::post('locations/{node}/deactivate', [W::class, 'deactivateNode'])->whereUuid('node');
Route::get('integration/transport', [W::class, 'transport']);
Route::put('integration/transport', [W::class, 'setTransport']);
Route::get('partners/{partner}/portal-users', [W::class, 'portalUsers'])->whereUuid('partner');
Route::post('partners/{partner}/portal-users', [W::class, 'createPortalUser'])->whereUuid('partner');
Route::get('partners/{partner}/invitations', [W::class, 'portalInvitations'])->whereUuid('partner');
Route::post('partners/{partner}/invitations', [W::class, 'invitePortalUser'])->whereUuid('partner')->middleware('throttle:20,1');
Route::delete('partners/{partner}/invitations/{invitation}', [W::class, 'revokeInvitation'])->whereUuid(['partner', 'invitation']);
Route::post('partners/{partner}/portal-users/{membership}/status', [W::class, 'setPortalUserActive'])->whereUuid('partner')->whereNumber('membership');
