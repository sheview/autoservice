<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->limitPublicPages();
    }

    /**
     * Pages anyone can open without signing in (an asset's QR page, its report form, ticket tracking)
     * are limited per address and per device, so they cannot be used to flood or to walk the codes.
     */
    private function limitPublicPages(): void
    {
        $device = fn (Request $request) => mb_strtolower((string) $request->route('company')).'/'.mb_strtolower((string) $request->route('code'));

        RateLimiter::for('qr-page', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perHour(300)->by('device:'.$device($request)),
        ]);
        RateLimiter::for('qr-report', fn (Request $request) => [
            Limit::perMinute(3)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('device:'.$device($request)),
        ]);
    }
}
