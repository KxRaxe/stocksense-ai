<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * An uploaded file that cannot be used at all (empty, unreadable, too big),
 * with a message fit to show the person who uploaded it.
 */
class InvalidImportFile extends RuntimeException {}
