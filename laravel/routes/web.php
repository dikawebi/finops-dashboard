<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccrualController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\D365Controller;
use App\Http\Middleware\EnsurePeriodOpen;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
    Route::post('/accruals', [AccrualController::class, 'store'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::get('/accounts', [OperationsController::class, 'accounts']);
    Route::get('/accruals', [OperationsController::class, 'accruals']);
    Route::get('/config', [OperationsController::class, 'config']);
    Route::put('/config', [OperationsController::class, 'updateConfig']);
    Route::get('/gl-data', [OperationsController::class, 'glData']);
    Route::post('/upload-gl', [OperationsController::class, 'uploadGl'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::delete('/gl-data', [OperationsController::class, 'deleteGl'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::get('/cost-matrix', [DashboardController::class, 'costMatrix']);
    Route::get('/financial-statements', [DashboardController::class, 'financialStatements']);
    Route::get('/inventory-valuation', [DashboardController::class, 'inventory']);
    Route::get('/production-summary', [DashboardController::class, 'production']);
    Route::get('/cost-per-mt', [DashboardController::class, 'costPerMt']);
    Route::get('/excel-cost-example', [DashboardController::class, 'excelCostExample']);
    Route::get('/db-cost-summary', [DashboardController::class, 'dbCostSummary']);
    Route::post('/db-cost-summary/remark', [DashboardController::class, 'saveDbCostRemark'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::get('/cost-variance', [DashboardController::class, 'variance']);
    Route::get('/integration-status', [DashboardController::class, 'integration']);
    Route::get('/d365/entities', [D365Controller::class, 'entities']);
    Route::post('/d365/sync', [D365Controller::class, 'sync']);
    Route::get('/d365/progress', [D365Controller::class, 'progress']);
    Route::get('/d365/staging', [D365Controller::class, 'staging']);
    Route::post('/d365/staging/preview', [D365Controller::class, 'preview']);
    Route::post('/d365/staging/import', [D365Controller::class, 'import']);
    Route::get('/d365/mappings', [D365Controller::class, 'mappings']);
    Route::post('/d365/mappings', [D365Controller::class, 'storeMapping']);
    Route::post('/d365/mappings/import', [D365Controller::class, 'importMappings']);
    Route::put('/d365/mappings/{id}', [D365Controller::class, 'updateMapping']);
    Route::delete('/d365/mappings/{id}', [D365Controller::class, 'deleteMapping']);
});
