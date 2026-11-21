<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('role:admin');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->middleware('role:admin');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('role:admin');

    // Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store'])->middleware('role:admin');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('role:admin');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('role:admin');
    Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->middleware('role:admin');

    // Sales
    Route::get('/sales', [SaleController::class, 'index']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::post('/sales', [SaleController::class, 'store']);
});
