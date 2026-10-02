<?php

namespace App\Http\Controllers;

use App\Enums\ImportType;

/**
 * Bringing a product list in from a file. Needs `catalog.manage` (see
 * routes/web.php).
 */
class ProductImportController extends ImportController
{
    public function type(): ImportType
    {
        return ImportType::Products;
    }
}
