<?php

use App\Enums\Permission;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalesImportController;
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

    // Importing sales from a file. Declared before the "sales/{sale}" routes so
    // "imports" is not mistaken for a sale id.
    Route::middleware('can:'.Permission::ImportSales->value)
        ->prefix('sales/imports')
        ->name('sales.imports.')
        ->group(function () {
            Route::get('/', [SalesImportController::class, 'index'])->name('index');
            Route::get('create', [SalesImportController::class, 'create'])->name('create');
            Route::get('template', [SalesImportController::class, 'template'])->name('template');
            Route::post('/', [SalesImportController::class, 'store'])->name('store');
            Route::get('{batch}', [SalesImportController::class, 'show'])->name('show')->whereNumber('batch');
            Route::put('{batch}', [SalesImportController::class, 'update'])->name('update')->whereNumber('batch');
            Route::post('{batch}/confirm', [SalesImportController::class, 'confirm'])->name('confirm')->whereNumber('batch');
            Route::get('{batch}/errors', [SalesImportController::class, 'errors'])->name('errors')->whereNumber('batch');
            Route::post('{batch}/undo', [SalesImportController::class, 'undo'])->name('undo')->whereNumber('batch');
            Route::delete('{batch}', [SalesImportController::class, 'cancel'])->name('cancel')->whereNumber('batch');
        });

    // Entering sales by hand and looking at sales history.
    Route::middleware('can:'.Permission::EnterSales->value)->group(function () {
        Route::get('sales/create', [SaleController::class, 'create'])->name('sales.create');
        Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
        Route::delete('sales/{sale}', [SaleController::class, 'destroy'])->name('sales.destroy')->whereNumber('sale');
    });
    Route::get('sales', [SaleController::class, 'index'])
        ->middleware('can:'.Permission::ViewSales->value)
        ->name('sales.index');

    // Administration: Owner only.
    Route::middleware('can:'.Permission::ManageUsers->value)->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/setup-link', [UserController::class, 'sendSetupLink'])->name('users.setup-link');
    });
});

require __DIR__.'/settings.php';
