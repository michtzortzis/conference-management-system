<?php

use App\Models\Conference;
use App\Models\ExpertiseArea;
use Database\Seeders\ConferencePhaseSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExpertiseAreaSeeder;

test('it seeds every expertise area the seeder defines', function () {
    $this->seed(ExpertiseAreaSeeder::class);

    expect(ExpertiseArea::count())->toBe(count(ExpertiseAreaSeeder::areas()));
});

test('it is idempotent — re-seeding leaves the same number of rows', function () {
    $this->seed(ExpertiseAreaSeeder::class);
    $first = ExpertiseArea::count();

    $this->seed(ExpertiseAreaSeeder::class);

    expect(ExpertiseArea::count())->toBe($first);
});

test('it updates a description in place instead of duplicating the name', function () {
    ExpertiseArea::create([
        'name' => ExpertiseAreaSeeder::areas()[0]['name'],
        'description' => 'Stale description from an earlier release.',
    ]);

    $this->seed(ExpertiseAreaSeeder::class);

    expect(ExpertiseArea::where('name', ExpertiseAreaSeeder::areas()[0]['name'])->count())->toBe(1)
        ->and(ExpertiseArea::firstWhere('name', ExpertiseAreaSeeder::areas()[0]['name'])->description)
        ->toBe(ExpertiseAreaSeeder::areas()[0]['description']);
});

test('DatabaseSeeder runs the expertise area seeder but leaves the opt-in phase demo data out', function () {
    $this->seed(DatabaseSeeder::class);

    expect(ExpertiseArea::count())->toBe(count(ExpertiseAreaSeeder::areas()))
        ->and(Conference::where('title', ConferencePhaseSeeder::CONFERENCE_TITLE)->count())->toBe(0);
});
