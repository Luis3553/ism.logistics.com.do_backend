<?php

namespace App\Providers;

use App\Http\Controllers\Service\ProGpsApiService;
use Illuminate\Support\ServiceProvider;

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
            $hash = $request->header('X-Hash-Token');

            // if (!$hash) {
            //     abort(400, 'Missing X-Hash-Token header');
            // }

            return new ProGpsApiService("cf229226a28d0bc8a646d34b7fa86377");
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
