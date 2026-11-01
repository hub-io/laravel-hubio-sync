<?php

namespace Hubio\Sync;

use Native\Mobile\AsyncTask;

/**
 * Synchronizes on a background PHP thread so the UI never waits for the network.
 */
class SyncTask extends AsyncTask
{
    private static float $requestedAt = 0;

    /**
     * @return array{status: string, rows?: int}
     */
    public function handle(): array
    {
        // Lets the transaction that triggered the task commit and a burst of taps finish.
        usleep(self::isFaked() ? 0 : 1_500_000);

        return app(SyncClient::class)->run();
    }

    /** Uploads a local change shortly after it was made; bursts of changes start one task. */
    public static function requestSoon(): void
    {
        if (! self::canRun() || microtime(true) - self::$requestedAt < 2) {
            return;
        }
        self::$requestedAt = microtime(true);
        self::dispatch()->timeout(120);
    }

    /** Periodic synchronization while the app is open (pushes cover the rest). */
    public static function requestIfDue(): void
    {
        if (self::canRun() && app(SyncClient::class)->isDue()) {
            self::$requestedAt = microtime(true);
            self::dispatch()->timeout(120);
        }
    }

    private static function canRun(): bool
    {
        return config('hubio-sync.background') && app(SyncClient::class)->isConfigured();
    }
}
