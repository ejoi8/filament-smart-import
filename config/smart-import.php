<?php

return [
    'importers' => [
        // Application-owned class strings implementing ImportDefinition.
    ],

    'storage' => [
        'disk' => env('SMART_IMPORT_STORAGE_DISK', 'local'),
        'root' => env('SMART_IMPORT_STORAGE_ROOT', 'smart-import'),
    ],

    'uploads' => [
        'max_file_size_bytes' => env('SMART_IMPORT_MAX_FILE_SIZE_BYTES', 10 * 1024 * 1024),
        'max_files_per_actor_per_hour' => env('SMART_IMPORT_MAX_FILES_PER_ACTOR_PER_HOUR', 20),
    ],

    'reading' => [
        'chunk_size' => 250,
        'max_rows' => 100000,
        'max_columns' => 256,
        'max_sheets' => 20,
        'max_cell_length' => 32767,
        'max_uncompressed_bytes' => 32 * 1024 * 1024,
        'preview_rows' => 20,
        'max_preview_rows' => 100,
    ],

    'queue' => [
        'connection' => env('SMART_IMPORT_QUEUE_CONNECTION'),
        'name' => env('SMART_IMPORT_QUEUE', 'imports'),
    ],

    'execution' => [
        'chunk_size' => 100,
        'job_tries' => 3,
        'job_backoff_seconds' => [10, 30, 60],
    ],

    'recovery' => [
        'stale_after_seconds' => 900,
        'max_recoveries' => 3,
    ],

    'progress' => [
        'polling_interval_seconds' => 2,
    ],

    'authorization' => [
        // Tenant-aware applications should replace this with a host-owned scope.
        'session_scope' => SmartImport\Services\ActorOwnedImportSessionScope::class,
        // The default keeps presets private to their creator; host-defined scopes may enable trusted sharing.
        'preset_scope' => SmartImport\Services\ActorOwnedImportPresetScope::class,
    ],

    'filament' => [
        // Explicit Filament panel ID => Laravel Gate ability; empty disables Hub registration.
        'panels' => [],
    ],
];
