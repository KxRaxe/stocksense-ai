<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The queue dashboard shows job payloads and failures: Owner only, in every
 * environment. Horizon's default lets anyone in when the environment is
 * "local", which is what the development stack (and the student manual) uses.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'local';
});

it('keeps people who are signed out away from the queue dashboard, even locally', function () {
    $this->get('/horizon')->assertForbidden();
});

it('keeps managers and inventory staff away from the queue dashboard, even locally', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get('/horizon')->assertForbidden();
})->with(['manager', 'inventoryStaff']);

it('lets the Owner open the queue dashboard', function () {
    $this->actingAs(User::factory()->owner()->create())->get('/horizon')->assertOk();
});
