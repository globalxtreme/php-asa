<?php

namespace GlobalXtreme\ASA;

use Illuminate\Support\ServiceProvider;

/**
 * Hanya dipakai di Laravel (terdaftar otomatis lewat package discovery). Menyediakan config/asa.php supaya nilai
 * environment tetap terbaca saat config Laravel di-cache.
 */
class ASAServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/asa.php', 'asa');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/asa.php' => $this->app->configPath('asa.php'),
            ], 'asa-config');
        }
    }
}
