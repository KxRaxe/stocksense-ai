<?php

namespace App\Services\Imports;

/**
 * What is wrong with one value in a file row. A distinct type, so a message
 * can never be mistaken for a valid value that happens to be a string (such
 * as the price "12.50").
 */
final class RowProblem
{
    public function __construct(public readonly string $message) {}
}
