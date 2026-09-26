<?php

namespace App\Providers;

use App\Services\NullPushNotificationService;
use App\Services\NullSmsService;
use App\Services\NullWhatsAppService;
use App\Services\PushNotificationService;
use App\Services\SmsService;
use App\Services\TwilioWhatsAppService;
use App\Services\WhatsAppService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Twilio\Rest\Client as TwilioClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsService::class, NullSmsService::class);
        // Slice E — real push delivery requires Firebase server credentials
        // (config('services.fcm.*')), which don't exist in this environment
        // yet; swap this binding for a real implementation once they do,
        // same as SmsService above.
        $this->app->singleton(PushNotificationService::class, NullPushNotificationService::class);

        // Unlike the two bindings above (always Null, swapped by hand later), this checks config
        // directly: real Twilio credentials are being configured now, not deferred indefinitely.
        // Falls back to Null if the flag is off or any required credential is missing, so a
        // half-configured .env degrades to "sent nowhere" rather than a boot-time crash.
        $this->app->singleton(WhatsAppService::class, function () {
            $twilio = config('services.twilio');
            $ready = (bool) config('communications.whatsapp.enabled')
                && filled($twilio['account_sid'] ?? null)
                && filled($twilio['api_key_sid'] ?? null)
                && filled($twilio['api_key_secret'] ?? null)
                && filled($twilio['whatsapp_from'] ?? null);

            if (! $ready) {
                return new NullWhatsAppService;
            }

            // Credentials can be present while twilio/sdk is missing from vendor
            // (incomplete deploy). class_exists autoloads; false → Null, not a 500.
            if (! class_exists(TwilioClient::class)) {
                Log::warning('whatsapp.twilio_sdk_missing');

                return new NullWhatsAppService;
            }

            $client = new TwilioClient($twilio['api_key_sid'], $twilio['api_key_secret'], $twilio['account_sid']);

            return new TwilioWhatsAppService($client, $twilio['whatsapp_from']);
        });
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

        // Follows a short link's redirect server-side (see MapLinkController) — one outbound
        // request per hop, so this stays modest rather than matching the per-file upload limit.
        RateLimiter::for('map-link-resolve', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) $request->user()->id);
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

        // Per-guest WhatsApp send button — one click per guest, so this is deliberately looser than
        // guest-bulk-send above (a host clicking through 30 individual guests isn't "bulk" abuse).
        // communications.whatsapp.hourly_cap_per_event (checked in CommunicationService) is the
        // per-event cost guard; this is just a per-user floor against a scripted hammer.
        RateLimiter::for('guest-whatsapp-send', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('admin-mutations', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->user()?->id ?? 'guest');
        });

        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('payment-initiate', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->user()?->id ?? $request->ip());
        });

        RateLimiter::for('ticket-resend', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->user()?->id ?? $request->ip());
        });

        // Covers both the invite and resend actions — a host repeatedly
        // inviting the same address is otherwise an open email spam/cost
        // vector, same reasoning as ticket-resend above.
        RateLimiter::for('staff-invite-send', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->user()?->id ?? $request->ip());
        });

        RateLimiter::for('ticket-hold', function (Request $request): Limit {
            $route = $request->route();
            $slug = $route?->parameter('slug');
            $suffix = is_string($slug) && $slug !== '' ? 'slug:'.$slug : 'global';

            return Limit::perMinute(10)->by((string) $request->ip().'|'.$suffix);
        });

        RateLimiter::for('ticket-checkout', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('ticket-verify', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // DomPDF + QR raster per hit — tighter than the HTML ticket page, which
        // stays unthrottled like rsvp.token.show. Cached in TicketController too.
        RateLimiter::for('ticket-download', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // Same reasoning as ticket-download: a DomPDF render plus a QR raster per
        // uncached hit. The pass page itself stays unthrottled like rsvp.token.show.
        RateLimiter::for('guest-pass-download', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('contribution-checkout', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('contribution-verify', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // API Slice A — web's registration form has no throttle at all (relies on validation +
        // the DB unique constraint alone), but a JSON API is a much easier target to script.
        // Login intentionally has no named limiter here — see Api\V1\Auth\LoginRequest's docblock.
        RateLimiter::for('api-auth-register', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });
    }
}
