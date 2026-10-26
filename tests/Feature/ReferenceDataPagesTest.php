<?php

use App\Models\Affiliation;
use App\Models\ExpertiseArea;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the affiliation list renders the affiliations it is given', function () {
    $user = User::factory()->create();

    Affiliation::create([
        'name' => 'Department of Computer Science',
        'type' => 'University',
        'country' => 'Greece',
    ]);

    $this->actingAs($user)
        ->get(route('affiliations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Affiliations/Index')
            ->has('affiliations', 1)
        );
});

test('an affiliation page renders its affiliated users with pivot metadata', function () {
    $user = User::factory()->create();

    $affiliation = Affiliation::create([
        'name' => 'Department of Computer Science',
        'type' => 'University',
        'country' => 'Greece',
    ]);
    $affiliation->users()->attach($user, [
        'department' => 'Computer Science',
        'position' => 'Researcher',
    ]);

    $this->actingAs($user)
        ->get(route('affiliations.show', $affiliation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Affiliations/Show')
            ->where('affiliation.name', 'Department of Computer Science')
            ->has('affiliation.users', 1)
            ->where('affiliation.users.0.pivot.position', 'Researcher')
        );
});

test('the expertise area list renders the areas it is given', function () {
    $user = User::factory()->create();

    ExpertiseArea::create([
        'name' => 'Cybersecurity, Privacy & Cryptography',
        'description' => 'Protecting systems, networks and data from threats.',
    ]);

    $this->actingAs($user)
        ->get(route('expertise-areas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ExpertiseAreas/Index')
            ->has('expertise_areas', 1)
        );
});

test('an expertise area page renders its experts with pivot metadata', function () {
    $user = User::factory()->create();

    $area = ExpertiseArea::create([
        'name' => 'Cybersecurity, Privacy & Cryptography',
        'description' => 'Protecting systems, networks and data from threats.',
    ]);
    $area->users()->attach($user, ['years_experience' => 7]);

    $this->actingAs($user)
        ->get(route('expertise-areas.show', $area))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ExpertiseAreas/Show')
            ->where('expertise_area.name', 'Cybersecurity, Privacy & Cryptography')
            ->has('expertise_area.users', 1)
            ->where('expertise_area.users.0.pivot.years_experience', 7)
        );
});
