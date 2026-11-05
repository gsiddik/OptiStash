<?php

use App\Http\Controllers\Api\InventoryController as I;
use Illuminate\Support\Facades\Route;

// Module 3: stock map, locks, cycle counts, analysis. Permission checks live in the controller.
Route::get('inventory/summary', [I::class, 'summary']);
Route::get('inventory/map/{warehouse}', [I::class, 'map'])->whereUuid('warehouse');
Route::get('inventory/locations/{node}/units', [I::class, 'unitsAt'])->whereUuid('node');
Route::post('stock-units/{id}/lock', [I::class, 'lockUnit'])->whereUuid('id');
Route::post('locations/{node}/lock-stock', [I::class, 'lockLocation'])->whereUuid('node');
Route::get('stocktakes', [I::class, 'stocktakes']);
Route::post('stocktakes', [I::class, 'startStocktake']);
Route::get('stocktakes/{id}', [I::class, 'stocktake'])->whereUuid('id');
Route::post('stocktakes/{id}/submit', [I::class, 'submitStocktake'])->whereUuid('id');
Route::post('stocktakes/{id}/cancel', [I::class, 'cancelStocktake'])->whereUuid('id');
Route::post('stocktakes/{id}/approve', [I::class, 'approveStocktake'])->whereUuid('id');
Route::post('stocktakes/{id}/reject', [I::class, 'rejectStocktake'])->whereUuid('id');
Route::post('stocktake-lines/{line}/count', [I::class, 'count'])->whereUuid('line');
Route::get('analysis/config', [I::class, 'analysisConfig']);
Route::put('analysis/config', [I::class, 'saveAnalysisConfig']);
Route::post('analysis/run', [I::class, 'runAnalysis']);
Route::get('analysis/runs', [I::class, 'analysisRuns']);
Route::get('analysis/results', [I::class, 'analysisResults']);
Route::get('analysis/matrix', [I::class, 'analysisMatrix']);
