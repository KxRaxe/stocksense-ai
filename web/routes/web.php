<?php

use App\Enums\Permission;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
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

    // Importing from a file works the same way for every kind of file. Each
    // set is declared before the routes with an id ("sales/{sale}",
    // "products/{product}") so "imports" is not mistaken for one.
    $importRoutes = function (string $controller, string $prefix, Permission $permission) {
        Route::middleware('can:'.$permission->value)
            ->prefix("{$prefix}/imports")
            ->name("{$prefix}.imports.")
            ->group(function () use ($controller) {
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('create', [$controller, 'create'])->name('create');
                Route::get('template', [$controller, 'template'])->name('template');
                Route::post('/', [$controller, 'store'])->name('store');
                Route::get('{batch}', [$controller, 'show'])->name('show')->whereNumber('batch');
                Route::put('{batch}', [$controller, 'update'])->name('update')->whereNumber('batch');
                Route::post('{batch}/confirm', [$controller, 'confirm'])->name('confirm')->whereNumber('batch');
                Route::get('{batch}/errors', [$controller, 'errors'])->name('errors')->whereNumber('batch');
                Route::post('{batch}/undo', [$controller, 'undo'])->name('undo')->whereNumber('batch');
                Route::delete('{batch}', [$controller, 'cancel'])->name('cancel')->whereNumber('batch');
            });
    };

    $importRoutes(ProductImportController::class, 'products', Permission::ManageCatalog);
    $importRoutes(SalesImportController::class, 'sales', Permission::ImportSales);

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
