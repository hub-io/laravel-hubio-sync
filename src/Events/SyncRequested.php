<?php

namespace Hubio\Sync\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched on the device by the Firebase plugin when Hubio.Space sends the silent data message
 * announcing that a household changed (also while the app is in the background).
 */
class SyncRequested
{
    use Dispatchable;

    public function __construct(public ?string $household = null) {}
}
