<?php

use App\Http\Controllers\Api\SupplierPortalController as S;
use Illuminate\Support\Facades\Route;

// Supplier portal: only for supplier-bound members (SupplierAccess); every query is limited to that supplier.
Route::get('supplier/products', [S::class, 'products']);
Route::get('supplier/warehouses', [S::class, 'warehouses']);
Route::get('supplier/asns', [S::class, 'asns']);
Route::post('supplier/asns', [S::class, 'create']);
Route::get('supplier/asns/{id}', [S::class, 'asn'])->whereUuid('id');
Route::put('supplier/asns/{id}', [S::class, 'update'])->whereUuid('id');
Route::post('supplier/asns/{id}/submit', [S::class, 'submit'])->whereUuid('id');
Route::post('supplier/asns/{id}/cancel', [S::class, 'cancel'])->whereUuid('id');
