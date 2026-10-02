<?php

use App\Enums\Permission;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalesImportController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

    // Forecasts: everyone with `forecasts.view` can look; starting a run needs `forecasts.run`.
    Route::middleware('can:'.Permission::ViewForecasts->value)
        ->prefix('forecasts')
        ->name('forecasts.')
        ->group(function () {
            Route::get('/', [ForecastController::class, 'index'])->name('index');
            Route::get('accuracy', [ForecastController::class, 'accuracy'])->name('accuracy');
            Route::get('products/{product}', [ForecastController::class, 'product'])->name('product')->whereNumber('product');
        });
    Route::post('forecasts/run', [ForecastController::class, 'run'])
        ->middleware('can:'.Permission::RunForecasts->value)
        ->name('forecasts.run');

    // What to reorder: everyone with `recommendations.view` can look; deciding (accept, adjust,
    // dismiss, cancel an order) needs `recommendations.decide`. The system never orders anything.
    Route::get('recommendations', [RecommendationController::class, 'index'])
        ->middleware('can:'.Permission::ViewRecommendations->value)
        ->name('recommendations.index');
    Route::middleware('can:'.Permission::DecideRecommendations->value)
        ->prefix('recommendations')
        ->name('recommendations.')
        ->group(function () {
            Route::post('refresh', [RecommendationController::class, 'refresh'])->name('refresh');
            Route::post('{recommendation}/accept', [RecommendationController::class, 'accept'])->name('accept')->whereNumber('recommendation');
            Route::post('{recommendation}/adjust', [RecommendationController::class, 'adjust'])->name('adjust')->whereNumber('recommendation');
            Route::post('{recommendation}/dismiss', [RecommendationController::class, 'dismiss'])->name('dismiss')->whereNumber('recommendation');
            Route::post('{recommendation}/cancel', [RecommendationController::class, 'cancel'])->name('cancel')->whereNumber('recommendation');
        });

    // Reports: what each person may open depends on the report (`reports.view`, or
    // `reports.inventory` for the stock report), so the controller checks per report.
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('{report}', [ReportController::class, 'show'])->name('show')->where('report', '[a-z-]+');
        Route::get('{report}/export/{format}', [ReportController::class, 'export'])->name('export')->where('report', '[a-z-]+')->where('format', 'xlsx|pdf');
    });

    // Everyone's own notifications.
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::get('{notification}/open', [NotificationController::class, 'open'])->name('open')->whereUuid('notification');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read')->whereUuid('notification');
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

    // The audit log: Owner only, read-only.
    Route::get('audit-log', [AuditLogController::class, 'index'])
        ->middleware('can:'.Permission::ViewAuditLog->value)
        ->name('audit-log.index');

    // Administration: Owner only. Shop-wide settings (not the per-person ones in routes/settings.php).
    Route::middleware('can:'.Permission::ManageSettings->value)
        ->prefix('system-settings')
        ->name('system-settings.')
        ->group(function () {
            Route::get('/', [SystemSettingsController::class, 'edit'])->name('edit');
            Route::put('/', [SystemSettingsController::class, 'update'])->name('update');
            Route::post('reset', [SystemSettingsController::class, 'reset'])->name('reset');
        });

    Route::middleware('can:'.Permission::ManageUsers->value)->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/setup-link', [UserController::class, 'sendSetupLink'])->name('users.setup-link');
    });
});

require __DIR__.'/settings.php';
