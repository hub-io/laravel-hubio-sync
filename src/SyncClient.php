<?php

namespace Hubio\Sync;

use Hubio\Sync\Models\Household;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Network;
use Native\Mobile\Facades\SecureStorage;

/**
 * Backup and sharing through Hubio.Space (api/sync/v1/{app}). The device registers anonymously
 * (its personal household is the backup), joins family or work households with an invite code
 * and exchanges changed rows: pending rows, photos and tombstones go up, rows changed by others
 * since the household cursor come down. The newest change of a row wins on both sides.
 */
class SyncClient
{
    private const string STATE_FILE = 'hubio-sync.json';

    private const string TOKEN_KEY = 'hubio.sync.device_token';

    private const int PAGE_SIZE = 500;

    private const string TIME_FORMAT = 'Y-m-d H:i:s.v';

    public function isConfigured(): bool
    {
        return filled(config('hubio-sync.api_url'));
    }

    public function isRegistered(): bool
    {
        return $this->token() !== null;
    }

    /**
     * @return array{device_name?: string, synced_at?: string, attempted_at?: string, error?: ?string, push_token?: ?string, token?: string}
     */
    public function state(): array
    {
        $state = json_decode((string) Storage::disk('local')->get(self::STATE_FILE), true);

        return is_array($state) ? $state : [];
    }

    public function isDue(): bool
    {
        $attemptedAt = $this->state()['attempted_at'] ?? null;

        return $this->isConfigured() && ($attemptedAt === null || Carbon::parse($attemptedAt)->lt(now()->subSeconds(config('hubio-sync.sync_interval_seconds'))));
    }

    public function deviceName(): string
    {
        return $this->state()['device_name'] ?? $this->defaultDeviceName();
    }

    /**
     * @return array{status: string, rows?: int}
     */
    public function run(): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'disabled'];
        }
        $this->save(['attempted_at' => now()->toIso8601ZuluString()]);
        if ((Network::status()?->connected ?? true) === false) {
            return ['status' => 'offline'];
        }
        try {
            $this->isRegistered() ? $this->refreshHouseholds() : $this->register();
            $pending = $this->pending();
            $rows = 0;
            foreach (Household::query()->orderByDesc('is_personal')->get() as $household) {
                $outgoing = array_values($pending['changes'][$household->uuid] ?? []);
                do {
                    $chunk = array_map(fn (array $change): array => $this->withFileReferences($household, $change), array_splice($outgoing, 0, self::PAGE_SIZE));
                    $body = $this->request()->post('households/'.$household->uuid.'/sync', ['cursor' => $household->cursor, 'changes' => $chunk])->throw()->json();
                    $rows += $this->apply($household, $body['changes'] ?? []);
                    $household->update(['cursor' => (int) $body['cursor']]);
                } while ($outgoing !== [] || ($body['has_more'] ?? false));
            }
            $this->markSynced($pending);
        } catch (ConnectionException|RequestException $exception) {
            $this->save(['error' => mb_substr($exception->getMessage(), 0, 300)]);

            return ['status' => 'failed'];
        }
        $this->save(['synced_at' => now()->toIso8601ZuluString(), 'error' => null]);

        return ['status' => 'synced', 'rows' => $rows];
    }

    public function register(?string $name = null): void
    {
        $name = trim($name ?? $this->deviceName()) ?: $this->defaultDeviceName();
        $resolver = config('hubio-sync.identity');
        $body = Http::baseUrl(config('hubio-sync.api_url'))->acceptJson()->timeout(config('hubio-sync.timeout_seconds'))
            ->post('devices', array_filter(['name' => $name, 'platform' => $this->platform(), 'identity' => $resolver ? app($resolver)() : null]))->throw()->json();
        if (! SecureStorage::set(self::TOKEN_KEY, $body['token'])) {
            // Without the keychain (development), the token stays in the app's private storage.
            $this->save(['token' => $body['token']]);
        }
        $this->save(['device_name' => $name]);
        $this->storeHouseholds($body['households']);
        if (filled($this->state()['push_token'] ?? null)) {
            $this->updatePushToken($this->state()['push_token']);
        }
    }

    public function rename(string $name): void
    {
        $this->save(['device_name' => $name]);
        if ($this->isRegistered()) {
            $this->request()->patch('device', ['name' => $name])->throw();
        }
    }

    /** Remembers the push token and sends it to the server once the device is registered. */
    public function updatePushToken(?string $token): void
    {
        $this->save(['push_token' => $token]);
        if ($this->isConfigured() && $this->isRegistered()) {
            $this->request()->patch('device', ['push_token' => $token])->throw();
        }
    }

    public function refreshHouseholds(): void
    {
        $this->storeHouseholds($this->request()->get('households')->throw()->json('households'));
    }

    public function createHousehold(string $name): Household
    {
        $this->ensureRegistered();

        return $this->storeHousehold($this->request()->post('households', ['name' => $name])->throw()->json('household'));
    }

    /**
     * @throws RequestException when the code is invalid (422)
     */
    public function joinHousehold(string $code): Household
    {
        $this->ensureRegistered();

        return $this->storeHousehold($this->request()->post('households/join', ['invite_code' => $code])->throw()->json('household'));
    }

    public function rotateInviteCode(Household $household): void
    {
        $this->storeHousehold($this->request()->post('households/'.$household->uuid.'/invite-code')->throw()->json('household'));
    }

    /** Leaves a shared household; the app's `forgetting` callback keeps its rows as personal ones. */
    public function leaveHousehold(Household $household): void
    {
        $this->request()->delete('households/'.$household->uuid)->throw();
        $this->forgetHousehold($household);
    }

    /**
     * Marks rows for upload again (e.g. after moving them into another household).
     *
     * @param  iterable<Model>  $models
     */
    public function resend(iterable $models): void
    {
        foreach ($models as $model) {
            DB::table($model->getTable())->where('id', $model->getKey())->update(['synced_at' => null]);
        }
    }

    /**
     * Authenticated request to the app's sync API for app-specific endpoints (e.g. Receptár's
     * shared recipes); registers the device first when needed.
     */
    public function api(): PendingRequest
    {
        $this->ensureRegistered();

        return $this->request();
    }

    private function ensureRegistered(): void
    {
        if (! $this->isRegistered()) {
            $this->register();
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('hubio-sync.api_url'))->withToken((string) $this->token())->acceptJson()->timeout(config('hubio-sync.timeout_seconds'));
    }

    private function token(): ?string
    {
        return SecureStorage::get(self::TOKEN_KEY) ?? ($this->state()['token'] ?? null);
    }

    /**
     * @param  list<array{uuid: string, name: string, is_personal: bool, invite_code: string, members: list<string>}>  $households
     */
    private function storeHouseholds(array $households): void
    {
        foreach ($households as $household) {
            $this->storeHousehold($household);
        }
        Household::query()->whereNotIn('uuid', array_column($households, 'uuid'))->get()->each(fn (Household $household) => $this->forgetHousehold($household));
    }

    /**
     * @param  array{uuid: string, name: string, is_personal: bool, invite_code: string, members: list<string>}  $household
     */
    private function storeHousehold(array $household): Household
    {
        return Household::query()->updateOrCreate(['uuid' => $household['uuid']], [
            'name' => $household['name'], 'is_personal' => $household['is_personal'],
            'invite_code' => $household['invite_code'], 'members' => $household['members'],
        ]);
    }

    private function forgetHousehold(Household $household): void
    {
        DB::transaction(function () use ($household) {
            $forgetting = config('hubio-sync.forgetting');
            if ($forgetting !== null) {
                app($forgetting)($household);
            }
            DB::table('sync_tombstones')->where('household_uuid', $household->uuid)->delete();
            DB::table('sync_files')->where('household_uuid', $household->uuid)->whereNull('path')->delete();
            $household->delete();
        });
    }

    /**
     * Pending rows and tombstones grouped by the household that receives them; rows going to a
     * shared household bring their dependencies (e.g. the product of an item) along.
     *
     * @return array{changes: array<string, array<string, array{type: string, uuid: string, model?: Model, data?: null, changed_at: string, deleted: bool}>>, rows: array<string, array<int, string>>, tombstones: list<int>}
     */
    private function pending(): array
    {
        $personal = Household::personalUuid();
        $known = Household::query()->pluck('uuid')->all();
        $changes = [];
        $rows = [];
        $add = function (string $household, Model $model, int $depth = 0) use (&$add, &$changes, $personal): void {
            $type = $this->typeOf($model);
            if ($type === null || isset($changes[$household][$type.'|'.$model->uuid])) {
                return;
            }
            $changes[$household][$type.'|'.$model->uuid] = ['type' => $type, 'uuid' => $model->uuid, 'model' => $model, 'changed_at' => $this->changedAt($model->getRawOriginal('updated_at')), 'deleted' => false];
            if ($household !== $personal && $depth < 4) {
                foreach ($model->syncDependencies() as $dependency) {
                    $add($household, $dependency, $depth + 1);
                }
            }
        };
        foreach ($this->models() as $type => $class) {
            $pending = $class::query()->where(fn ($query) => $query->whereNull('synced_at')->orWhereColumn('updated_at', '!=', 'synced_at'))->get();
            foreach ($pending as $model) {
                $rows[$type][$model->getKey()] = (string) $model->getRawOriginal('updated_at');
                foreach ($this->households($model->syncHouseholds(), $known, $personal) as $household) {
                    $add($household, $model);
                }
            }
        }
        $tombstones = DB::table('sync_tombstones')->orderBy('id')->get();
        foreach ($tombstones as $tombstone) {
            foreach ($this->households([$tombstone->household_uuid], $known, $personal) as $household) {
                $changes[$household][$tombstone->type.'|'.$tombstone->uuid] = ['type' => $tombstone->type, 'uuid' => $tombstone->uuid, 'data' => null, 'changed_at' => $this->changedAt($tombstone->deleted_at), 'deleted' => true];
            }
        }

        return ['changes' => array_map(fn (array $list): array => $this->ordered($list), $changes), 'rows' => $rows, 'tombstones' => $tombstones->pluck('id')->all()];
    }

    /**
     * Households a row goes to; unknown (left) households fall back to the personal one.
     *
     * @param  list<?string>  $households
     * @param  list<string>  $known
     * @return list<string>
     */
    private function households(array $households, array $known, ?string $personal): array
    {
        return array_values(array_unique(array_filter(array_map(fn (?string $uuid): ?string => in_array($uuid, $known, true) ? $uuid : $personal, $households))));
    }

    /**
     * Payload of a pending row for one household, with its photos uploaded there first.
     *
     * @param  array{type: string, uuid: string, model?: Model, data?: null, changed_at: string, deleted: bool}  $change
     * @return array{type: string, uuid: string, data: array<string, mixed>|null, changed_at: string, deleted: bool}
     */
    private function withFileReferences(Household $household, array $change): array
    {
        $model = $change['model'] ?? null;
        unset($change['model']);
        if ($model === null) {
            return $change;
        }
        $data = $model->syncData();
        foreach ($model->syncFiles() as $key => $column) {
            $data[$key] = filled($model->{$column}) ? $this->upload($household, (string) $model->{$column}) : null;
        }

        return [...$change, 'data' => $data];
    }

    /**
     * @return array{uuid: string, checksum: string}|null
     */
    private function upload(Household $household, string $path): ?array
    {
        $disk = Storage::disk(config('hubio-sync.files.disk'));
        if (! $disk->exists($path)) {
            return null;
        }
        $checksum = hash_file('sha256', $disk->path($path));
        $known = DB::table('sync_files')->where('household_uuid', $household->uuid)->where('checksum', $checksum)->value('remote_uuid');
        if ($known === null) {
            $response = $this->request()->attach('file', $disk->get($path), basename($path))->post('households/'.$household->uuid.'/files');
            if ($response->status() === 422) {
                return null;
            }
            $known = $response->throw()->json('file.uuid');
            DB::table('sync_files')->updateOrInsert(['household_uuid' => $household->uuid, 'checksum' => $checksum], ['remote_uuid' => $known, 'path' => $path]);
        }

        return ['uuid' => $known, 'checksum' => $checksum];
    }

    /**
     * Local path of a photo of another device, downloaded once per checksum.
     *
     * @param  array{uuid?: string, checksum?: string}|null  $reference
     */
    private function download(Household $household, ?array $reference): ?string
    {
        if (! isset($reference['uuid'], $reference['checksum'])) {
            return null;
        }
        $disk = Storage::disk(config('hubio-sync.files.disk'));
        $existing = DB::table('sync_files')->where('checksum', $reference['checksum'])->whereNotNull('path')->pluck('path')->first(fn (string $path): bool => $disk->exists($path));
        if ($existing === null) {
            $response = $this->request()->get('households/'.$household->uuid.'/files/'.$reference['uuid']);
            if ($response->status() === 404) {
                return null;
            }
            $content = $response->throw()->body();
            if (! hash_equals($reference['checksum'], hash('sha256', $content))) {
                return null;
            }
            $extension = ['image/png' => 'png', 'image/webp' => 'webp'][$response->header('Content-Type')] ?? 'jpg';
            $existing = trim(config('hubio-sync.files.directory'), '/').'/'.$reference['checksum'].'.'.$extension;
            $disk->put($existing, $content);
        }
        DB::table('sync_files')->updateOrInsert(['household_uuid' => $household->uuid, 'checksum' => $reference['checksum']], ['remote_uuid' => $reference['uuid'], 'path' => $existing]);

        return $existing;
    }

    /**
     * Sorts changes so referenced rows arrive before the rows that reference them.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    private function ordered(array $changes): array
    {
        $order = array_flip(array_keys($this->models()));
        $changes = array_values($changes);
        usort($changes, fn (array $a, array $b): int => ($order[$a['type']] ?? 99) <=> ($order[$b['type']] ?? 99));

        return $changes;
    }

    /**
     * Marks uploaded rows as synced unless they changed again meanwhile and forgets the
     * uploaded tombstones.
     *
     * @param  array{rows: array<string, array<int, string>>, tombstones: list<int>}  $pending
     */
    private function markSynced(array $pending): void
    {
        DB::transaction(function () use ($pending) {
            foreach ($pending['rows'] as $type => $rows) {
                $table = (new ($this->models()[$type]))->getTable();
                foreach ($rows as $id => $updatedAt) {
                    DB::table($table)->where('id', $id)->where('updated_at', $updatedAt)->update(['synced_at' => $updatedAt]);
                }
            }
            DB::table('sync_tombstones')->whereIn('id', $pending['tombstones'])->delete();
        });
    }

    /**
     * Writes changes of other devices without model events (app rules already ran on the device
     * that made them) unless the local row is newer. A deletion only removes a row that still
     * belongs to that household.
     *
     * @param  list<array{type: string, uuid: string, data: ?array<string, mixed>, changed_at: string, deleted: bool}>  $changes
     */
    private function apply(Household $household, array $changes): int
    {
        $models = $this->models();
        $changes = $this->ordered(array_filter($changes, fn (array $change): bool => isset($models[$change['type']])));
        foreach ($changes as $index => $change) {
            foreach (($change['deleted'] ? [] : (new $models[$change['type']])->syncFiles()) as $key => $column) {
                $changes[$index]['paths'][$column] = $this->download($household, $change['data'][$key] ?? null);
            }
        }
        $applied = 0;
        DB::transaction(function () use ($household, $changes, $models, &$applied) {
            foreach ($changes as $change) {
                $class = $models[$change['type']];
                $table = (new $class)->getTable();
                $changedAt = Carbon::parse($change['changed_at'])->utc()->format(self::TIME_FORMAT);
                $local = DB::table($table)->where('uuid', $change['uuid'])->first();
                if ($local !== null && Carbon::parse($local->updated_at)->format(self::TIME_FORMAT) >= $changedAt) {
                    continue;
                }
                if ($change['deleted']) {
                    $households = $local === null ? [] : $class::query()->find($local->id)?->syncHouseholds() ?? [];
                    if ($local !== null && ($households === [] || in_array($household->uuid, $households, true))) {
                        DB::table($table)->where('id', $local->id)->delete();
                        $applied++;
                    }

                    continue;
                }
                $values = $class::syncAttributes($change['data'] ?? [], $household);
                if ($values === null) {
                    continue;
                }
                $values = [...$values, ...($change['paths'] ?? []), 'uuid' => $change['uuid'], 'updated_at' => $changedAt, 'synced_at' => $changedAt];
                $id = $local?->id ?? $class::syncExisting($values);
                if ($id === null) {
                    $id = DB::table($table)->insertGetId($values + ['created_at' => $changedAt]);
                } else {
                    DB::table($table)->where('id', $id)->update($values);
                }
                if (method_exists($class, 'syncApplied')) {
                    $class::syncApplied((int) $id, $change['data'] ?? [], $household);
                }
                $applied++;
            }
        });

        return $applied;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function models(): array
    {
        return (array) config('hubio-sync.models');
    }

    private function typeOf(Model $model): ?string
    {
        $type = array_search($model::class, $this->models(), true);

        return $type === false ? null : $type;
    }

    private function changedAt(?string $timestamp): string
    {
        return Carbon::parse($timestamp ?? now())->utc()->toIso8601ZuluString('millisecond');
    }

    private function platform(): ?string
    {
        $platform = strtolower((string) (json_decode(Device::getInfo() ?? '', true)['platform'] ?? ''));

        return in_array($platform, ['ios', 'android'], true) ? $platform : null;
    }

    private function defaultDeviceName(): string
    {
        $info = json_decode(Device::getInfo() ?? '', true);

        return mb_substr(trim((string) ($info['name'] ?? $info['model'] ?? '')), 0, 80) ?: (string) config('hubio-sync.default_device_name', 'Môj telefón');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function save(array $changes): void
    {
        Storage::disk('local')->put(self::STATE_FILE, json_encode([...$this->state(), ...$changes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
