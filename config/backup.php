<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup name
    |--------------------------------------------------------------------------
    */

    'backup' => [
        'name' => env('APP_NAME', 'laravel-backup'),

        'source' => [
            'files' => [
                'include' => [
                    base_path('app'),
                    base_path('config'),
                    base_path('database'),
                    base_path('public'),
                    base_path('resources'),
                    base_path('routes'),
                    base_path('.env'),
                    storage_path('app/private/news'),
                    storage_path('app/private/firmware'),
                ],
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('framework'),
                    storage_path('logs'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],

            'databases' => [
                'mysql',
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'filename_prefix' => 'uti-',
            // Второй экземпляр копий — на другом носителе (флешка, сетевая папка):
            // BACKUP_EXTERNAL_PATH=/mnt/backup в .env. Без него копии только на SD-карте сервера.
            'disks' => array_values(array_filter([
                'local',
                env('BACKUP_EXTERNAL_PATH') ? 'backup_external' : null,
            ])),
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'notifications' => [
            \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class         => ['mail'],
            \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class => ['mail'],
            \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class        => ['mail'],
            \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class     => [],
            \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class    => [],
        ],

        'notifiable' => \Spatie\Backup\Notifications\Notifiable::class,

        'mail' => [
            'to' => 'admin@uti.local',
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitor backups
    |--------------------------------------------------------------------------
    */

    // Пробное восстановление (backup:verify --restore): ОТДЕЛЬНАЯ пустая база, куда
    // загружается дамп для проверки. Пользователю БД нужны права на неё. Не рабочая база!
    'verify_database' => env('BACKUP_VERIFY_DATABASE'),

    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            'disks' => array_values(array_filter(['local', env('BACKUP_EXTERNAL_PATH') ? 'backup_external' : null])),
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    'cleanup' => [
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 14,
            'keep_daily_backups_for_days' => 14,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 6,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],

];