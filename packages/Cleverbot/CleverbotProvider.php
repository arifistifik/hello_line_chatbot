<?php

namespace Packages\Cleverbot;

use Illuminate\Support\ServiceProvider;
use Packages\Cleverbot\Cleverbot;

class CleverbotProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('cleverbot', function ($app) {
            return new Cleverbot(config('services.cleverbot.api_key'));
        });
    }
}
