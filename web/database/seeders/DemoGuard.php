<?php

namespace Database\Seeders;

use RuntimeException;

/**
 * Keeps the demo seeders out of production: they create accounts with a password that
 * is written in the README. A throwaway test stack can allow it with ALLOW_DEMO_DATA.
 */
final class DemoGuard
{
    public static function refuseInProduction(): void
    {
        if (app()->isProduction() && ! config('demo.allowed')) {
            throw new RuntimeException('The demo accounts and data are for development and demonstrations only, and are not created in production.');
        }
    }
}
