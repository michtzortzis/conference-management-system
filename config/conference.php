<?php

return [
    'max_reviewers' => 5,

    /*
     * Opt-in switch for the back-dated demo data in ConferencePhaseSeeder,
     * which leaves a conference mid-bidding for demos and UAT. Off by default
     * because it moves every deadline into the past; the standalone route
     * (`php artisan db:seed --class=ConferencePhaseSeeder`) works either way.
     */
    'seed_advanced_phases' => (bool) env('SEED_ADVANCED_PHASES', false),

    /*
     * Auto-detected conflict-of-interest layer. When disabled, scoring is
     * skipped entirely; declared conflicts are still enforced.
     */
    'coi_auto_detect' => true,

    /*
     * Scoring weights for auto-detected conflicts. Strong signals (>= hard
     * threshold) hard-block assignment; weak signals (>= soft threshold) flag
     * for the chair to review/override.
     */
    'coi_thresholds' => [
        'email_exact' => 5,
        'affiliation_dept' => 5,
        'affiliation' => 4,
        'email_domain' => 2,
        'name' => 1,
        'name_domain' => 3,
        'hard_threshold' => 4,
        'soft_threshold' => 2,
    ],

    /*
     * Email domains that never contribute to an email-domain signal (free
     * providers where anyone can register).
     */
    'coi_free_email_domains' => [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'outlook.com',
        'hotmail.com',
        'aol.com',
        'icloud.com',
        'protonmail.com',
    ],
];
