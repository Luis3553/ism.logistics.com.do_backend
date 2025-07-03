<?php

namespace App\Providers;

use App\Services\ProGpsApiService;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class ProGpsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(ProGpsApiService::class, function ($app) {
            $request = $app['request'];
            $user = $request->attributes->get('user') ?? null;
            if (!$user || !$user->hash) throw new RuntimeException('Missing user hash.');
            return new ProGpsApiService($user->hash);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
