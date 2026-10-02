<?php

use App\Enums\Permission;
use App\Enums\Role;

/**
 * The frontend declares the role and permission names as TypeScript types so
 * the interface can show or hide things. If they drift from the PHP enums, a
 * menu item could silently disappear, so this test compares the two lists.
 *
 * @return list<string>
 */
function tsUnionMembers(string $typeName): array
{
    $source = file_get_contents(resource_path('js/types/auth.ts'));

    expect(preg_match("/export type {$typeName} =(.*?);/s", $source, $block))->toBe(1);

    preg_match_all("/'([^']+)'/", $block[1], $members);

    return $members[1];
}

it('declares the same permissions in TypeScript as in PHP', function () {
    expect(tsUnionMembers('Permission'))
        ->toEqualCanonicalizing(array_column(Permission::cases(), 'value'));
});

it('declares the same roles in TypeScript as in PHP', function () {
    expect(tsUnionMembers('Role'))->toEqualCanonicalizing(Role::values());
});
