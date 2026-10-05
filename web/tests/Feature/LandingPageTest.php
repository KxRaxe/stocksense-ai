<?php

use App\Models\User;

it('shows the landing page to someone who is signed out', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome')->where('demo', true));
});

it('takes someone who is signed in straight to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

it('never mentions the demo accounts in production, where they do not exist', function () {
    $this->app['env'] = 'production';

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome')->where('demo', false));
});
