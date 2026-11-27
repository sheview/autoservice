<?php

namespace App\Providers;

use App\Validation\Validator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        // Behind a proxy that ends HTTPS (nginx in front of the app's container), the request reaches
        // PHP as plain http: links, assets and Inertia requests built from it would be http on an https
        // page, and the browser blocks them (mixed content). An https APP_URL says how users reach us.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Every new or changed password: at least 8 characters, upper and lower case, and a special character.
        Password::defaults(fn () => Password::min(8)->mixedCase()->symbols());

        // Dates with a four-digit year only (App\Validation\Validator).
        ValidatorFacade::resolver(fn ($translator, $data, $rules, $messages, $attributes) => new Validator($translator, $data, $rules, $messages, $attributes));
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
        // Tracking by number or serial (guessable): per address, and per company asked about.
        RateLimiter::for('track', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(120)->by('company:'.mb_strtolower($request->getHost().'/'.$request->string('company')->trim()->value())),
        ]);
        RateLimiter::for('track-link', fn (Request $request) => Limit::perMinute(60)->by('ip:'.$request->ip()));
        // Outside technicians signing up: a person sends the form once or twice, not in a stream.
        RateLimiter::for('technician-signup', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(30)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('qr-report', fn (Request $request) => [
            Limit::perMinute(3)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('device:'.$device($request)),
        ]);
    }
}
