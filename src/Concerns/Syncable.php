<?php

namespace Hubio\Sync\Concerns;

use Hubio\Sync\Models\Household;
use Hubio\Sync\SyncTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A model synchronized with Hubio.Space. The table needs `uuid`, `synced_at` (string) and
 * timestamps; timestamps keep milliseconds (the newest change wins), a row whose `updated_at`
 * differs from `synced_at` is pending upload and a deleted row leaves tombstones.
 *
 * The model describes its record:
 * - syncData(): payload uploaded to the server (reference other rows by UUID or natural key),
 * - syncHouseholds(): households the row belongs to (Household::personalUuid() for private rows),
 * - syncDependencies(): rows that must reach the same shared household (e.g. an item's product),
 * - syncAttributes(): local columns built from a downloaded payload (null = skip for now),
 * - syncFiles(): payload keys holding photos => column with the path on the files disk.
 * - optional static syncApplied(int $id, array $data, Household $household): rows that belong to
 *   a downloaded record (e.g. a recipe's ingredient lines), written after the record; use the
 *   query builder there so no change is recorded for upload.
 *
 * @mixin Model
 */
trait Syncable
{
    /** @var list<string>|null households resolved before deletion (relations may be gone after) */
    public ?array $syncDeletedHouseholds = null;

    /**
     * @return array<string, mixed>
     */
    abstract public function syncData(): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    abstract public static function syncAttributes(array $data, Household $household): ?array;

    /**
     * @return list<string>
     */
    public function syncHouseholds(): array
    {
        return array_values(array_filter([Household::personalUuid()]));
    }

    /**
     * @return list<Model>
     */
    public function syncDependencies(): array
    {
        return [];
    }

    /**
     * @return array<string, string> payload key => column holding the path on the files disk
     */
    public function syncFiles(): array
    {
        return [];
    }

    /**
     * Local row that a downloaded record with a new UUID should merge into (e.g. the same
     * product stocked on two phones before sharing); its UUID becomes the server's.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function syncExisting(array $attributes): ?int
    {
        return null;
    }

    public static function bootSyncable(): void
    {
        static::creating(function (Model $model) {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(fn () => SyncTask::requestSoon());
        static::deleting(function (Model $model) {
            $model->syncDeletedHouseholds = $model->syncHouseholds();
        });
        static::deleted(function (Model $model) {
            self::recordTombstone($model->getTable(), $model->uuid, $model->syncDeletedHouseholds ?? $model->syncHouseholds());
            SyncTask::requestSoon();
        });
    }

    /**
     * Remembers a deletion for the given households (e.g. children removed by a database cascade).
     *
     * @param  list<string>  $households
     */
    public static function recordTombstone(string $type, ?string $uuid, array $households): void
    {
        if ($uuid === null) {
            return;
        }
        $deletedAt = now()->format('Y-m-d H:i:s.v');
        foreach ($households as $household) {
            DB::table('sync_tombstones')->insert(['type' => $type, 'uuid' => $uuid, 'household_uuid' => $household, 'deleted_at' => $deletedAt]);
        }
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }
}
