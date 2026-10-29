<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
    Route::get('/cost-matrix', [DashboardController::class, 'costMatrix']);
    Route::get('/financial-statements', [DashboardController::class, 'financialStatements']);
    Route::get('/inventory-valuation', [DashboardController::class, 'inventory']);
    Route::get('/production-summary', [DashboardController::class, 'production']);
    Route::get('/cost-per-mt', [DashboardController::class, 'costPerMt']);
    Route::get('/cost-variance', [DashboardController::class, 'variance']);
});
