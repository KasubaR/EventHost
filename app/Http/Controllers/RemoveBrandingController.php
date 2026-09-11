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
 * The event-scoped checkout page for the "remove EventHost branding" addon
 * — K250, any plan, per event. Initiate/verify are handled by the existing
 * generic PaymentController (plan_key = 'remove_branding'); this controller
 * only renders the page. See plans/remove-branding.md.
 */
class RemoveBrandingController extends Controller
{
    public function show(Event $event, LencoService $lenco): View|RedirectResponse
    {
        $this->authorize('update', $event);

        if ($event->branding_removed) {
            return redirect()->route('events.show', $event)->with('status', 'branding-already-removed');
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

        return view('billing.remove-branding-checkout', [
            'event' => $event,
            'addon' => BillingPlan::getAddon('remove_branding'),
            'currency' => BillingPlan::currency(),
            'banks' => $banks,
            'bankTransferEnabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
        ]);
    }
}
