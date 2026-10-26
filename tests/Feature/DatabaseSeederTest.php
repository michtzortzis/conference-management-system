<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('every account the default seed creates is verified', function () {
    $this->seed(DatabaseSeeder::class);

    // Every route in routes/web.php carries the `verified` middleware, so a
    // demo account with a null email_verified_at logs in and is then locked out
    // of the whole application.
    expect(User::count())->toBeGreaterThan(1)
        ->and(User::whereNull('email_verified_at')->exists())->toBeFalse();
});

test('a seeded committee member reaches a verified route with no tampering', function () {
    $this->seed(DatabaseSeeder::class);

    $committee = User::where('email', 'committee1@test.com')->firstOrFail();

    $this->actingAs($committee)
        ->get(route('dashboard'))
        ->assertOk();
});

test('re-seeding verifies the admin account it deliberately keeps', function () {
    User::factory()->unverified()->create(['email' => 'admin@test.com']);

    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin@test.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
});
