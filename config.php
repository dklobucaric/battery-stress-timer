<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer - Configuration
|--------------------------------------------------------------------------
|
| Database, app, admin and stress profile configuration.
|
| Vibe code by Dalibor Klobučarić & my friend ChatGPT
|
|--------------------------------------------------------------------------
*/

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'nameofyourdatabase',
        'user' => 'usernameofyourdatabase',
        'pass' => 'passwordofyourdatabase',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name' => 'Battery Stress Timer',
        'version' => '1.0.0',
        'heartbeat_seconds' => 60,
        'default_profile' => 'medium',
    ],

    'admin' => [
        /*
         * If true:
         * - admin.php uses the fallback credentials below.
         *
         * If false:
         * - admin.php reads users from the admin_users database table.
         *
         * Recommended:
         * - local/dev: true is acceptable
         * - public/production: false
         */
        'fallback_enabled' => false,

        /*
         * Fallback credentials.
         * Used only when fallback_enabled = true.
         *
         * Do not use admin/admin on a public server.
         */
        'fallback_user' => 'admin',
        'fallback_pass' => 'admin',
    ],

    'profiles' => [
        'no_load' => [
            'label' => 'No Load, just timer',
            'description' => 'Timer only with animated background, battery telemetry and server logging. No CPU workload.',
            'workload_enabled' => false,
            'worker_max' => 0,
            'workload_interval_seconds' => 0,
            'workload_duration_seconds' => 0,
        ],
        'light' => [
            'label' => 'Light',
            'description' => 'Low CPU bursts, mostly display/timer test',
            'worker_max' => 1,
            'workload_interval_seconds' => 60,
            'workload_duration_seconds' => 5,
        ],

        'medium' => [
            'label' => 'Medium',
            'description' => 'Balanced CPU + display stress',
            'worker_max' => 4,
            'workload_interval_seconds' => 60,
            'workload_duration_seconds' => 15,
        ],

        'high' => [
            'label' => 'High',
            'description' => 'Heavier CPU workload',
            'worker_max' => 8,
            'workload_interval_seconds' => 60,
            'workload_duration_seconds' => 40,
        ],
    ],
];
