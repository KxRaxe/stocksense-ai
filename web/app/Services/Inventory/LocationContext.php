<?php

namespace App\Services\Inventory;

use App\Models\Location;

/**
 * Answers "which location are we working with?" for stock reads and writes.
 *
 * Everything that touches stock goes through here rather than looking up a
 * location itself, so adding branches later means changing this one class (to
 * pick the location the user chose) instead of hunting through the code.
 * Today there is a single location, so it always returns the default.
 */
class LocationContext
{
    private ?Location $current = null;

    public function current(): Location
    {
        return $this->current ??= Location::defaultLocation();
    }

    public function id(): int
    {
        return $this->current()->id;
    }
}
