<?php

namespace App\Enums;

/**
 * The three user roles from the StockSense AI proposal and the permissions
 * each one holds. This is the single source of truth for the access matrix;
 * RolesAndPermissionsSeeder copies it into the database.
 */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case InventoryStaff = 'inventory_staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::InventoryStaff => 'Inventory staff',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            self::Manager => [
                Permission::ViewCatalog,
                Permission::ManageCatalog,
                Permission::ViewInventory,
                Permission::AdjustStock,
                Permission::ViewSales,
                Permission::EnterSales,
                Permission::ImportSales,
                Permission::ViewForecasts,
                Permission::RunForecasts,
                Permission::ViewRecommendations,
                Permission::DecideRecommendations,
                Permission::ViewReports,
                Permission::ViewInventoryReports,
            ],

            // Can view products and edit stock, enter and import sales, see
            // recommendations and inventory reports. Cannot run forecasts,
            // decide on recommendations, or see other reports.
            self::InventoryStaff => [
                Permission::ViewCatalog,
                Permission::ViewInventory,
                Permission::AdjustStock,
                Permission::ViewSales,
                Permission::EnterSales,
                Permission::ImportSales,
                Permission::ViewRecommendations,
                Permission::ViewInventoryReports,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
