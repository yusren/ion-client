<?php

namespace Ptpn\IonClient;

use Illuminate\Support\ServiceProvider;

class IonClientServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/ion-client.php',
            'ion-client'
        );

        $this->app->singleton(IonClient::class, function ($app) {
            return new IonClient($app['config']->get('ion-client', []));
        });
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/Config/ion-client.php' => config_path('ion-client.php'),
            ], 'ion-client-config');
        }
    }
}
