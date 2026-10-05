<?php

namespace App\Enums;

use App\Services\Imports\ImportDefinition;
use App\Services\Products\ProductImportDefinition;
use App\Services\Sales\SalesImportDefinition;

/**
 * What a file imports. Each type plugs its own columns, checks, processing and
 * undo into the shared upload, preview, queue, error report and undo flow.
 * The value is also the first part of the type's route names (sales.imports.show).
 */
enum ImportType: string
{
    case Sales = 'sales';
    case Products = 'products';

    public function definition(): ImportDefinition
    {
        return app(match ($this) {
            self::Sales => SalesImportDefinition::class,
            self::Products => ProductImportDefinition::class,
        });
    }
}
