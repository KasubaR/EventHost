<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\LencoService;
use App\Support\BillingPlan;
use App\Support\PaymentLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The event-scoped checkout page for paying an approved free-registration
 * event's admin-set quote — plans/public-private-portals.md Phase 4c, Step
 * 2. Initiate/verify are handled by the existing generic PaymentController
 * (plan_key = 'public_registration_quote'), mirroring
 * RemoveBrandingController exactly; this controller only renders the page.
 */
class PublicRegistrationPaymentController extends Controller
{
    public function show(Event $event, LencoService $lenco): View|RedirectResponse
    {
        $this->authorize('update', $event);

        if (! $event->awaitingPublicRegistrationPayment()) {
            return redirect()->route('events.show', $event)->with('status', 'public-registration-not-payable');
        }

        // Same fetch-with-fallback and cache key as PaymentController::show()
        // — one live bank list for the whole app, not a second cache entry.
        $environment = (string) config('services.lenco.environment', 'sandbox');
        $cacheKey = "lenco.banks.zm.{$environment}";

        $banks = Cache::remember($cacheKey, now()->addHours(6), function () use ($lenco) {
            try {
                $fetched = $lenco->getBanks();
                if ($fetched !== []) {
                    return $fetched;
                }
            } catch (\Throwable $e) {
                PaymentLog::info('banks.fetch_failed', ['message' => $e->getMessage()]);
            }

            return BillingPlan::fallbackBanks();
        });

        return view('billing.public-registration-checkout', [
            'event' => $event,
            'amount' => (float) $event->public_registration_quote_amount,
            'currency' => BillingPlan::currency(),
            'banks' => $banks,
            'bankTransferEnabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
        ]);
    }
}
