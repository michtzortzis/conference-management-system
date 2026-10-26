<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the application seeders.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ExpertiseAreaSeeder::class,
            TestUserSeeder::class,
        ]);

        // Opt-in, not default: the phase demo data back-dates every deadline,
        // which is right for demos and UAT but wrong for a fresh install. Run
        // `php artisan db:seed --class=ConferencePhaseSeeder` or set
        // SEED_ADVANCED_PHASES=true. It has to run last because TestUserSeeder
        // deletes every user except the admin.
        if (config('conference.seed_advanced_phases')) {
            $this->call(ConferencePhaseSeeder::class);
        }
    }
}
