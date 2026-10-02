<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * The JSON Schemas and example payloads in `contracts/`, shared with the ML
 * service. Laravel's requests and the responses it parses are checked against
 * the very files the ML service's own tests use.
 */
final class Contracts
{
    public static function path(string $relative): string
    {
        return base_path('../contracts/'.$relative);
    }

    /**
     * An example payload from contracts/fixtures.
     *
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::path("fixtures/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * What is wrong with `$data` as seen by the named schema ("forecast-request", ...); empty if nothing is.
     *
     * @return list<string>
     */
    public static function errors(string $schema, mixed $data): array
    {
        $definition = json_decode((string) file_get_contents(self::path("{$schema}.schema.json")));

        $result = (new Validator)->validate(Helper::toJSON($data), $definition);

        if ($result->isValid()) {
            return [];
        }

        $messages = [];

        foreach ((new ErrorFormatter)->format($result->error(), false) as $path => $problems) {
            foreach ((array) $problems as $problem) {
                $messages[] = "{$path}: {$problem}";
            }
        }

        return $messages;
    }
}
