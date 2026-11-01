<?php

/*
 * Backup and sharing through Hubio.Space. `api_url` already names the app, e.g.
 * https://hubio.space/api/sync/v1/spajza; empty turns synchronization off (offline only).
 */
return [
    'api_url' => rtrim((string) env('HUBIO_SYNC_URL', ''), '/'),

    // Start a background sync after local changes and periodically while a screen polls.
    'background' => (bool) env('HUBIO_SYNC_BACKGROUND', true),
    'sync_interval_seconds' => (int) env('HUBIO_SYNC_INTERVAL', 60),
    'timeout_seconds' => 20,

    /*
     * Synchronized models by record type, in dependency order (referenced rows first). Every
     * model uses the Hubio\Sync\Concerns\Syncable trait.
     */
    'models' => [],

    // Invokable class returning a stable device identity (e.g. from the iCloud Keychain) so a
    // reinstalled app gets its device and backup back; null registers a new device each time.
    'identity' => null,

    // Invokable class called with a Household the device left or lost, before it is forgotten
    // (e.g. to keep its rows as personal ones and mark them for upload to the backup).
    'forgetting' => null,

    'default_device_name' => 'Môj telefón',

    // Downloaded photos of other devices: disk and directory of the stored relative path.
    'files' => [
        'disk' => 'local',
        'directory' => 'sync-files',
    ],
];
