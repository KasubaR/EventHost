<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomQuote;
use App\Models\InvitationTemplate;
use App\Services\LencoService;
use App\Services\PopularBillingPlanResolver;
use App\Support\BillingPlan;
use App\Support\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * JSON sibling of App\Http\Controllers\PaymentController::show() (Slice E) —
 * plans/banks listing only. initiate()/verify()/verifyByReference() are NOT
 * forked here: those three methods already return response()->json(...)
 * exclusively and are guard-agnostic ($request->user()/auth()->id()), so
 * routes/api.php points new Sanctum-guarded routes straight at the existing
 * PaymentController instead of duplicating a ~200-line transactional method.
 * Remove-branding purchases go through the same three routes with
 * plan_key=remove_branding — there is no separate remove-branding endpoint,
 * on web or here (see RemoveBrandingController's own docblock).
 */
class BillingController extends Controller
{
    public function show(Request $request, PopularBillingPlanResolver $popularPlans): JsonResponse
    {
        $user = $request->user();
        $environment = (string) config('services.lenco.environment', 'sandbox');
        $cacheKey = "lenco.banks.zm.{$environment}";

        $banks = Cache::remember($cacheKey, now()->addHours(6), function () {
            try {
                $fetched = app(LencoService::class)->getBanks();
                if ($fetched !== []) {
                    return $fetched;
                }
            } catch (\Throwable $e) {
                PaymentLog::info('banks.fetch_failed', [
                    'message' => $e->getMessage(),
                ]);
            }

            return BillingPlan::fallbackBanks();
        });

        $pendingQuote = CustomQuote::pendingFor($user);

        return response()->json([
            'plans' => BillingPlan::all(),
            'currency' => BillingPlan::currency(),
            'banks' => $banks,
            'bank_transfer_enabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
            'active_template_count' => InvitationTemplate::activeCount(),
            'popular_plan_key' => $popularPlans->resolve(),
            'pending_custom_quote' => $pendingQuote === null ? null : [
                'id' => $pendingQuote->id,
                'amount' => (string) $pendingQuote->amount,
                'credits_granted' => $pendingQuote->credits_granted,
                'note' => $pendingQuote->note,
            ],
        ]);
    }
}
