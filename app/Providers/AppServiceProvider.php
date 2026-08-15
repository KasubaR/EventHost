<?php

namespace App\Providers;

use App\Services\NullSmsService;
use App\Services\SmsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsService::class, NullSmsService::class);
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        Password::defaults(function () {
            $rule = Password::min(8)->letters()->mixedCase()->numbers();

            if (! app()->runningUnitTests()) {
                $rule = $rule->uncompromised();
            }

            return $rule;
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('invitation-design', function (Request $request): Limit {
            $perMinute = max(1, (int) config('invitations.design_updates_per_minute', 20));

            return Limit::perMinute($perMinute)->by((string) $request->user()->id);
        });

        // One request per picked file, and a user can reasonably pick eleven at
        // once, so this sits well above the design-save limit.
        RateLimiter::for('invitation-media', function (Request $request): Limit {
            $perMinute = max(1, (int) config('invitations.media_uploads_per_minute', 60));

            return Limit::perMinute($perMinute)->by((string) $request->user()->id);
        });

        RateLimiter::for('rsvp-submit', function (Request $request): Limit {
            $route = $request->route();
            $suffix = 'global';
            if ($route !== null) {
                $token = $route->parameter('token');
                $slug = $route->parameter('slug');
                if (is_string($token) && $token !== '') {
                    $suffix = 'token:'.$token;
                } elseif (is_string($slug) && $slug !== '') {
                    $suffix = 'slug:'.$slug;
                }
            }

            return Limit::perMinute(10)->by((string) $request->ip().'|'.$suffix);
        });

        RateLimiter::for('staff-checkin', function (Request $request): Limit {
            $route = $request->route();
            $staffToken = $route?->parameter('staffToken');
            $suffix = is_string($staffToken) && $staffToken !== '' ? 'token:'.$staffToken : 'global';

            return Limit::perMinute(30)->by((string) $request->ip().'|'.$suffix);
        });

        RateLimiter::for('table-upload', function (Request $request): Limit {
            $route = $request->route();
            $code = $route?->parameter('code');
            $suffix = is_string($code) && $code !== '' ? 'code:'.$code : 'global';

            return Limit::perMinutes(10, 10)->by((string) $request->ip().'|'.$suffix);
        });

        RateLimiter::for('guest-bulk-send', function (Request $request): Limit {
            $perHour = max(1, (int) config('communications.bulk_send_per_hour', 12));
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perHour($perHour)->by((string) $userId);
        });

        RateLimiter::for('admin-mutations', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->user()?->id ?? 'guest');
        });

        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });
    }
}
