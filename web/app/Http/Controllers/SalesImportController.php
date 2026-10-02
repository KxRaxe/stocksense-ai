<?php

namespace App\Http\Controllers;

use App\Enums\ImportType;

/**
 * Importing sales history. Needs `sales.import` (see routes/web.php).
 */
class SalesImportController extends ImportController
{
    public function type(): ImportType
    {
        return ImportType::Sales;
    }
}
