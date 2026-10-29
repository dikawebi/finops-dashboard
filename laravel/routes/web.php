<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api')->group(function () {
    Route::get('/cost-matrix', [DashboardController::class, 'costMatrix']);
    Route::get('/financial-statements', [DashboardController::class, 'financialStatements']);
    Route::get('/inventory-valuation', [DashboardController::class, 'inventory']);
    Route::get('/production-summary', [DashboardController::class, 'production']);
});
