<?php

use App\Http\Controllers\Api\QualityController as Q;
use Illuminate\Support\Facades\Route;

// Module 5: non-conformance, CAPA, calibration, controlled documents, audit trail. Permission checks live in the controller.
Route::get('quality/people', [Q::class, 'people']);
Route::get('quality/iso-map', [Q::class, 'isoMap']);
Route::post('nonconformances', [Q::class, 'raiseNc']);
Route::get('capas', [Q::class, 'capas']);
Route::post('capas', [Q::class, 'createCapa']);
Route::get('capas/{id}', [Q::class, 'capaShow'])->whereUuid('id');
Route::post('capas/{id}/analysis', [Q::class, 'analyse'])->whereUuid('id');
Route::post('capas/{id}/actions', [Q::class, 'addAction'])->whereUuid('id');
Route::post('capas/{id}/verify', [Q::class, 'verify'])->whereUuid('id');
Route::post('capas/{id}/close', [Q::class, 'closeCapa'])->whereUuid('id');
Route::post('capa-actions/{action}/complete', [Q::class, 'completeAction'])->whereUuid('action');
Route::get('instruments', [Q::class, 'instruments']);
Route::post('instruments', [Q::class, 'registerInstrument']);
Route::get('instruments/{id}', [Q::class, 'instrument'])->whereUuid('id');
Route::post('instruments/{id}/calibrations', [Q::class, 'calibrate'])->whereUuid('id');
Route::post('instruments/{id}/deactivate', [Q::class, 'deactivateInstrument'])->whereUuid('id');
Route::get('documents', [Q::class, 'documents']);
Route::post('documents', [Q::class, 'createDocument']);
Route::get('documents/{id}', [Q::class, 'document'])->whereUuid('id');
Route::post('documents/{id}/versions', [Q::class, 'newVersion'])->whereUuid('id');
Route::put('document-versions/{version}', [Q::class, 'editVersion'])->whereUuid('version');
Route::post('document-versions/{version}/submit', [Q::class, 'submitVersion'])->whereUuid('version');
Route::post('document-versions/{version}/approve', [Q::class, 'approveVersion'])->whereUuid('version');
Route::post('document-versions/{version}/return', [Q::class, 'returnVersion'])->whereUuid('version');
Route::get('audit/export', [Q::class, 'auditExport']);
Route::get('audit', [Q::class, 'audit']);
