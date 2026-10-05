<?php

namespace App\Services\Reporting\Reports\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Keeps text from being taken for a formula.
 *
 * Product names, notes and the like are typed by people, and a spreadsheet
 * program treats text that starts with "=", "+", "-" or "@" as a formula to
 * run, which can be made to read files or send data elsewhere ("formula
 * injection"). Any such text is stored as plain text, and the cell is marked so
 * the program shows it as typed even when the file is later re-saved. Numbers,
 * dates and everything else are written as usual.
 */
class SafeValueBinder extends DefaultValueBinder
{
    /** Characters that make a spreadsheet read text as a formula (or, for tab and return, as a new cell). */
    private const RISKY_START = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::RISKY_START, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            $cell->getStyle()->setQuotePrefix(true);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
