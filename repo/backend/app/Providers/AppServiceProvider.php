<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment() !== 'testing' && empty(config('vetops.auth.captcha_bypass_token'))) {
            throw new \RuntimeException(
                'VETOPS_AUTH_CAPTCHA_BYPASS_TOKEN must be set to a non-empty secret in non-testing environments.'
            );
        }

        date_default_timezone_set(config('vetops.timezone', 'UTC'));

        Carbon::serializeUsing(
            static fn (Carbon $carbon): string => $carbon->copy()->utc()->format('Y-m-d\TH:i:s\Z')
        );
    }
}
