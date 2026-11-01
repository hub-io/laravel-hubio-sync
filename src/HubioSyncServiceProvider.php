<?php

namespace Hubio\Sync;

use Hubio\Sync\Events\SyncRequested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Events\PushNotification\TokenGenerated;

class HubioSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hubio-sync.php', 'hubio-sync');
        $this->app->singleton(SyncClient::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/hubio-sync.php' => config_path('hubio-sync.php')], 'hubio-sync-config');
        // A data message from Hubio.Space runs this in an ephemeral runtime, also in the background.
        Event::listen(SyncRequested::class, fn () => $this->app->make(SyncClient::class)->run());
        Event::listen(TokenGenerated::class, fn (TokenGenerated $event) => $this->app->make(SyncClient::class)->updatePushToken($event->token));
    }
}
