<?php

namespace App\Enums;

enum SaleSource: string
{
    case Manual = 'manual';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Entered by hand',
            self::Import => 'Imported',
        };
    }
}
