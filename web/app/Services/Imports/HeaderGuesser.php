<?php

namespace App\Services\Imports;

/**
 * Matches each field an import needs to the column of an uploaded file whose
 * header looks like it.
 */
final class HeaderGuesser
{
    /**
     * @param  array<string, array{aliases: list<string>}>  $fields  Field => the header names it is known by (lower case, letters and digits only), best match first
     * @param  list<string>  $headers  The file's header row
     * @return array<string, int|null> Field => 0-based column number, or null when nothing fits
     */
    public static function guess(array $fields, array $headers): array
    {
        $normalised = array_map(
            fn (string $header) => preg_replace('/[^a-z0-9]/', '', strtolower($header)),
            $headers,
        );

        $columns = [];
        $taken = [];

        foreach ($fields as $field => $details) {
            $columns[$field] = null;

            foreach ($details['aliases'] as $alias) {
                $index = array_search($alias, $normalised, true);

                if ($index !== false && ! in_array($index, $taken, true)) {
                    $columns[$field] = $index;
                    $taken[] = $index;

                    break;
                }
            }
        }

        return $columns;
    }
}
