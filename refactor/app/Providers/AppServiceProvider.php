<?php

// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\JobService;
use UserService;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(JobService::class, function ($app) {
            return new JobService();
        });

        $this->app->singleton(UserService::class, function ($app) {
            return new UserService();
        });
    }

    public function boot()
    {
        //
    }
}