<?php

use App\Enums\Permission;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Catalog changes: Owner and Manager. These come first so "products/create"
    // is not mistaken for a product id by the show route below.
    Route::middleware('can:'.Permission::ManageCatalog->value)->group(function () {
        Route::resource('categories', CategoryController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
        Route::resource('products', ProductController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');
        Route::patch('products/{product}/restore', [ProductController::class, 'restore'])->name('products.restore');
    });

    // Looking at the catalog and stock: everyone.
    Route::middleware('can:'.Permission::ViewCatalog->value)->group(function () {
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show')->whereNumber('product');
    });

    Route::get('inventory', [InventoryController::class, 'index'])
        ->middleware('can:'.Permission::ViewInventory->value)
        ->name('inventory.index');

    // Recording goods received and stock-take corrections: everyone.
    Route::middleware('can:'.Permission::AdjustStock->value)->group(function () {
        Route::post('products/{product}/restock', [StockController::class, 'restock'])->name('products.restock');
        Route::post('products/{product}/adjust', [StockController::class, 'adjust'])->name('products.adjust');
    });

    // Administration: Owner only.
    Route::middleware('can:'.Permission::ManageUsers->value)->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/setup-link', [UserController::class, 'sendSetupLink'])->name('users.setup-link');
    });
});

require __DIR__.'/settings.php';
