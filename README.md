# Laravel Hubio Sync
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hub-io/laravel-hubio-sync.svg?style=flat-square)](https://packagist.org/packages/hub-io/laravel-hubio-sync)
[![Total Downloads](https://img.shields.io/packagist/dt/hub-io/laravel-hubio-sync.svg?style=flat-square)](https://packagist.org/packages/hub-io/laravel-hubio-sync)

Offline-first backup, sharing and photo synchronization for [NativePHP Mobile](https://nativephp.com) apps through the Hubio.Space sync API (`api/sync/v1/{app}`).

The app keeps working on its local SQLite database. In the background the device registers anonymously (its personal household is the backup), can create or join family / work households with an invite code, and exchanges changed rows with the server: pending rows, photos and deletions go up, rows changed by other devices come down. The newest change of a row wins on both sides.

## Support us

We invest a lot of time and give our hearts to work in [Open Source](https://hubio.sk).

## Requirements

- PHP 8.3+
- Laravel 13
- [nativephp/mobile](https://nativephp.com) 4.6+
- Optional: `nativephp/mobile-secure-storage` (device token in the keychain) and `nativephp/mobile-firebase` (push-triggered sync)

## Installation

You can install the package via composer:

```bash
composer require hub-io/laravel-hubio-sync
```

The migrations (`households`, `sync_tombstones`, `sync_files`) are loaded automatically:

```bash
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="hubio-sync-config"
```

This is the content of the published config file:

```php
return [
    'api_url' => rtrim((string) env('HUBIO_SYNC_URL', ''), '/'),

    // Start a background sync after local changes and periodically while a screen polls.
    'background' => (bool) env('HUBIO_SYNC_BACKGROUND', true),
    'sync_interval_seconds' => (int) env('HUBIO_SYNC_INTERVAL', 60),
    'timeout_seconds' => 20,

    // Synchronized models by record type, in dependency order (referenced rows first).
    'models' => [],

    // Invokable class returning a stable device identity, so a reinstalled app gets its backup back.
    'identity' => null,

    // Invokable class called with a Household the device left or lost, before it is forgotten.
    'forgetting' => null,

    'default_device_name' => 'Môj telefón',

    // Downloaded photos of other devices: disk and directory of the stored relative path.
    'files' => [
        'disk' => 'local',
        'directory' => 'sync-files',
    ],
];
```

Set the API URL of your app in `.env`. An empty URL turns synchronization off and the app works offline only:

```dotenv
HUBIO_SYNC_URL=https://hubio.space/api/sync/v1/your-app
```

## Usage

### Make a model syncable

The table needs a `uuid` column, a `synced_at` string column and timestamps. Timestamps keep milliseconds (the newest change wins); a row whose `updated_at` differs from `synced_at` is pending upload, and a deleted row leaves a tombstone.

```php
use Hubio\Sync\Concerns\Syncable;
use Hubio\Sync\Models\Household;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use Syncable;

    protected $fillable = ['name', 'color'];

    /** Payload uploaded to the server (reference other rows by UUID or natural key). */
    public function syncData(): array
    {
        return ['name' => $this->name, 'color' => $this->color];
    }

    /** Local columns built from a downloaded payload (null skips the record for now). */
    public static function syncAttributes(array $data, Household $household): ?array
    {
        return ['name' => (string) ($data['name'] ?? ''), 'color' => $data['color'] ?? '#6B7280'];
    }
}
```

Register it in `config/hubio-sync.php`, referenced rows first:

```php
'models' => [
    'categories' => Category::class,
    'products' => Product::class,
],
```

Optional hooks on the model:

| Method | Purpose |
| --- | --- |
| `syncHouseholds(): array` | Households the row belongs to (defaults to the personal backup). |
| `syncDependencies(): array` | Rows that must reach the same shared household (e.g. an item's product). |
| `syncFiles(): array` | Payload keys holding photos => column with the path on the files disk. |
| `static syncExisting(array $attributes): ?int` | Local row a downloaded record with a new UUID merges into. |
| `static syncApplied(int $id, array $data, Household $household): void` | Writes child rows of a downloaded record (use the query builder). |

### Synchronize

Saving or deleting a syncable model starts a background sync shortly after (bursts of changes start one task). While a screen is open, poll periodically:

```php
use Hubio\Sync\SyncTask;

SyncTask::requestIfDue();
```

Or run it in the foreground:

```php
use Hubio\Sync\SyncClient;

$result = app(SyncClient::class)->run(); // ['status' => 'synced', 'rows' => 12]
```

The status is one of `disabled`, `offline`, `failed` or `synced`. `SyncClient::state()` returns the last sync time and error.

### Households

```php
use Hubio\Sync\SyncClient;
use Hubio\Sync\Models\Household;

$sync = app(SyncClient::class);

$household = $sync->createHousehold('Family');
$household->formattedInviteCode(); // ABCD-EFGH

$sync->joinHousehold('ABCD-EFGH');
$sync->rotateInviteCode($household);
$sync->leaveHousehold($household);

Household::personalUuid(); // the device's private backup
Household::sharedUuids();
```

### Shares

Publish a record under a code for everyone using the same app; others open it and rate it with 1–5 stars:

```php
use Hubio\Sync\Shares;

$shares = app(Shares::class);

$code = $shares->share($recipe);   // ABCD2345
Shares::format($code);             // ABCD-2345

$shared = $shares->open('ABCD-2345');
$shares->rate('ABCD-2345', 5, 'Delicious');
$shares->summaries();               // ratings of the records this device shared
$shares->unshare($recipe);
```

### Push-triggered sync

With `nativephp/mobile-firebase`, the push token is sent to the server automatically. When a household changes, Hubio.Space sends a silent data message with the event `Hubio\Sync\Events\SyncRequested`, which runs a sync, also while the app is in the background.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hubio s.r.o.](https://github.com/hub-io)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
