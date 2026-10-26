<?php

use App\Models\User;

test('guests visiting the root url are sent to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users visiting the root url are sent to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

test('the login page is rendered once, with the props it requires', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->has('canResetPassword')
        );
});
