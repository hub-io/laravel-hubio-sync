<?php

namespace Hubio\Sync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local copy of a Hubio.Space household this device belongs to: its personal backup or a group
 * (family, work) sharing data. `cursor` is the last server revision downloaded.
 */
class Household extends Model
{
    protected $fillable = ['uuid', 'name', 'is_personal', 'invite_code', 'members', 'cursor'];

    protected $casts = ['is_personal' => 'boolean', 'members' => 'array', 'cursor' => 'integer'];

    public static function personal(): ?self
    {
        return self::query()->where('is_personal', true)->first();
    }

    public static function personalUuid(): ?string
    {
        return self::query()->where('is_personal', true)->value('uuid');
    }

    /**
     * @return list<string>
     */
    public static function sharedUuids(): array
    {
        return self::query()->where('is_personal', false)->pluck('uuid')->all();
    }

    /** Invite code formatted for reading aloud, e.g. "ABCD-EFGH". */
    public function formattedInviteCode(): string
    {
        return substr((string) $this->invite_code, 0, 4).'-'.substr((string) $this->invite_code, 4);
    }
}
