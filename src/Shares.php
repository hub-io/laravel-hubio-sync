<?php

namespace Hubio\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Records published under a code for everyone who uses the same app (the server lists the
 * shareable types per app in `native_sync.apps.{app}.shares`). Others open the record's current
 * version and rate it with 1–5 stars once (changeable); the authors see the ratings.
 */
class Shares
{
    public function __construct(private readonly SyncClient $sync) {}

    /**
     * Uploads the row if needed and returns its share code (the same one when shared again).
     */
    public function share(Model $model): string
    {
        // Registers the device on first use and uploads the row before it is published.
        $this->sync->run();
        [$type, $household] = $this->target($model->fresh() ?? $model);

        return (string) $this->sync->api()->post('households/'.$household.'/shares', ['type' => $type, 'uuid' => $model->uuid])->throw()->json('code');
    }

    public function unshare(Model $model): void
    {
        [$type, $household] = $this->target($model);
        $this->sync->api()->delete('households/'.$household.'/shares/'.$type.'/'.$model->uuid)->throw();
    }

    /**
     * Code and rating of every row this device's households shared, keyed by "type|uuid".
     *
     * @return array<string, array{code: string, average: float|null, count: int}>
     */
    public function summaries(): array
    {
        $summaries = [];
        foreach (Models\Household::query()->pluck('uuid') as $household) {
            foreach ($this->sync->api()->get('households/'.$household.'/shares')->throw()->json('shares') as $share) {
                $summaries[$share['type'].'|'.$share['uuid']] = ['code' => $share['code'], ...$share['rating']];
            }
        }

        return $summaries;
    }

    /**
     * @return array{code: string, type: string, uuid: string, record: array<string, mixed>, author: ?string, is_own: bool, rating: array{average: float|null, count: int}, my_rating: ?array{stars: int, comment: ?string}, comments: list<array{name: ?string, stars: int, comment: string, rated_at: string}>}
     *
     * @throws RequestException 404 when the code is unknown or the record is not shared any more
     */
    public function open(string $code): array
    {
        return $this->sync->api()->get('shared/'.self::normalize($code))->throw()->json();
    }

    /**
     * @return array{rating: array{average: float|null, count: int}, my_rating: array{stars: int, comment: ?string}}
     *
     * @throws RequestException 422 when rating an own record
     */
    public function rate(string $code, int $stars, ?string $comment = null): array
    {
        return $this->sync->api()->put('shared/'.self::normalize($code).'/rating', ['stars' => $stars, 'comment' => $comment])->throw()->json();
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** Formatted code, e.g. ABCD-2345. */
    public static function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * @return array{0: string, 1: string} record type and the household the row lives in
     */
    private function target(Model $model): array
    {
        $type = array_search($model::class, (array) config('hubio-sync.models'), true);
        if ($type === false) {
            throw new RuntimeException($model::class.' is not synchronized (hubio-sync.models).');
        }
        $household = $model->syncHouseholds()[0] ?? Models\Household::personalUuid() ?? throw new RuntimeException('The row is not backed up yet.');

        return [$type, $household];
    }
}
