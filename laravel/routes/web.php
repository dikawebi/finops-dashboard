<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\AccrualController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\D365Controller;
use App\Http\Controllers\FinancialAccountMappingController;
use App\Http\Controllers\InventoryManualInputController;
use App\Http\Middleware\EnsurePeriodOpen;

Route::get('/login', [AuthController::class, 'showLogin'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest')->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::post('/api/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/api/logout', [AuthController::class, 'logout'])->middleware('auth');

Route::get('/', function () {
    return view('welcome');
})->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'administrator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdministratorController::class, 'index'])->name('index');
    Route::post('/users', [AdministratorController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [AdministratorController::class, 'update'])->name('users.update');
});

Route::middleware('auth')->prefix('api')->group(function () {
    Route::post('/accruals', [AccrualController::class, 'store'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::get('/accounts', [OperationsController::class, 'accounts']);
    Route::get('/accruals', [OperationsController::class, 'accruals']);
    Route::get('/config', [OperationsController::class, 'config']);
    Route::put('/config', [OperationsController::class, 'updateConfig']);
    Route::get('/gl-data', [OperationsController::class, 'glData']);
    Route::get('/gl-transaction-review', [OperationsController::class, 'glTransactionReview']);
    Route::post('/upload-gl', [OperationsController::class, 'uploadGl'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::delete('/gl-data', [OperationsController::class, 'deleteGl'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::get('/cost-matrix', [DashboardController::class, 'costMatrix']);
    Route::put('/report-cost-closing', [DashboardController::class, 'updateReportCostClosing']);
    Route::get('/financial-statements', [DashboardController::class, 'financialStatements']);
    Route::get('/financial-mappings', [FinancialAccountMappingController::class, 'index']);
    Route::put('/financial-mappings/{accountCode}', [FinancialAccountMappingController::class, 'update']);
    Route::get('/inventory-valuation', [DashboardController::class, 'inventory']);
    Route::get('/inventory-inputs', [InventoryManualInputController::class, 'index']);
    Route::post('/inventory-inputs', [InventoryManualInputController::class, 'store']);
    Route::post('/inventory-inputs/{id}/void', [InventoryManualInputController::class, 'void']);
    Route::get('/production-summary', [DashboardController::class, 'production']);
    Route::get('/overview-daily', [DashboardController::class, 'overviewDaily']);
    Route::get('/overview-allocation', [DashboardController::class, 'overviewAllocation']);
    Route::get('/equipment-cost', [DashboardController::class, 'equipmentCost']);
    Route::get('/cost-per-mt', [DashboardController::class, 'costPerMt']);
    Route::get('/excel-cost-example', [DashboardController::class, 'excelCostExample']);
    Route::get('/db-cost-summary', [DashboardController::class, 'dbCostSummary']);
    Route::post('/db-cost-summary/remark', [DashboardController::class, 'saveDbCostRemark'])->middleware(['auth', EnsurePeriodOpen::class]);
    Route::get('/cost-variance', [DashboardController::class, 'variance']);
    Route::post('/cost-variance/remark', [DashboardController::class, 'saveVarianceRemark'])->middleware(EnsurePeriodOpen::class);
    Route::get('/integration-status', [DashboardController::class, 'integration']);
    Route::post('/google-sheets/preview', [DashboardController::class, 'previewGoogleSheets']);
    Route::post('/google-sheets/sync', [DashboardController::class, 'syncGoogleSheets']);
    Route::get('/d365/entities', [D365Controller::class, 'entities']);
    Route::post('/d365/sync', [D365Controller::class, 'sync']);
    Route::post('/d365/sync/stop', [D365Controller::class, 'stopSync']);
    Route::get('/d365/progress', [D365Controller::class, 'progress']);
    Route::get('/d365/staging', [D365Controller::class, 'staging']);
    Route::post('/d365/staging/preview', [D365Controller::class, 'preview']);
    Route::post('/d365/staging/import', [D365Controller::class, 'import']);
    Route::get('/d365/mappings', [D365Controller::class, 'mappings']);
    Route::get('/d365/account-hierarchy', [D365Controller::class, 'accountHierarchy']);
    Route::post('/d365/mappings', [D365Controller::class, 'storeMapping']);
    Route::post('/d365/mappings/import', [D365Controller::class, 'importMappings']);
    Route::put('/d365/mappings/{id}', [D365Controller::class, 'updateMapping']);
    Route::delete('/d365/mappings/{id}', [D365Controller::class, 'deleteMapping']);
});
