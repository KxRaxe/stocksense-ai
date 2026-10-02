<?php

namespace App\Enums;

/**
 * Every ability the application can check. The values are what is stored in
 * the permissions table and what the frontend receives, so renaming a case
 * value is a breaking change (the seeder prunes names that no longer exist).
 */
enum Permission: string
{
    // Administration (Owner only)
    case ManageUsers = 'users.manage';
    case ManageSettings = 'settings.manage';
    case ViewAuditLog = 'audit.view';

    // Products, categories and stock
    case ViewCatalog = 'catalog.view';
    case ManageCatalog = 'catalog.manage';
    case ViewInventory = 'inventory.view';
    case AdjustStock = 'inventory.adjust';

    // Sales data
    case ViewSales = 'sales.view';
    case EnterSales = 'sales.enter';
    case ImportSales = 'sales.import';

    // Forecasting and replenishment
    case ViewForecasts = 'forecasts.view';
    case RunForecasts = 'forecasts.run';
    case ViewRecommendations = 'recommendations.view';
    case DecideRecommendations = 'recommendations.decide';

    // Reports
    case ViewReports = 'reports.view';
    case ViewInventoryReports = 'reports.inventory';
}
