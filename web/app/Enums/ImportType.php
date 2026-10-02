<?php

namespace App\Enums;

use App\Services\Imports\ImportDefinition;
use App\Services\Products\ProductImportDefinition;
use App\Services\Sales\SalesImportDefinition;

/**
 * What a file imports. Each type plugs its own columns, checks, processing and
 * undo into the shared upload, preview, queue, error report and undo flow.
 */
enum ImportType: string
{
    case Sales = 'sales';
    case Products = 'products';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'sales',
            self::Products => 'products',
        };
    }

    /**
     * The first part of this type's route names ("sales" for sales.imports.show).
     */
    public function routePrefix(): string
    {
        return $this->value;
    }

    public function definition(): ImportDefinition
    {
        return app(match ($this) {
            self::Sales => SalesImportDefinition::class,
            self::Products => ProductImportDefinition::class,
        });
    }
}
