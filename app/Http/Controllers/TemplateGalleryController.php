<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionTier;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Support\BillingPlan;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public template gallery. The signed-in library (TemplateLibraryController::index) keeps its own page with
 * search and plan tabs; this one is the marketing view, grouped into what Base and Pro unlock.
 */
class TemplateGalleryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = InvitationTemplateCategory::query()
            ->whereHas('invitationTemplates', fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $requested = $request->query('category');
        $category = is_string($requested) ? $categories->firstWhere('slug', $requested) : null;

        $templates = InvitationTemplate::query()
            ->where('is_active', true)
            ->when($category, fn ($query) => $query->whereHas('categories', fn ($q) => $q->whereKey($category->id)))
            ->with('categories')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Every tier above Base sits with Pro, so a Pro+ or Enterprise template never falls out of both groups.
        [$proTemplates, $baseTemplates] = $templates->partition(
            fn (InvitationTemplate $template) => $template->requiredTierRank() >= SubscriptionTier::Pro->rank()
        );

        return view('templates.gallery', [
            'baseTemplates' => $baseTemplates->values(),
            'proTemplates' => $proTemplates->values(),
            'categories' => $categories,
            'category' => $category,
            'templateCount' => InvitationTemplate::activeCount(),
            'currency' => BillingPlan::currency(),
            'basePrice' => BillingPlan::listAmountForTier(SubscriptionTier::Base),
            'proPrice' => BillingPlan::listAmountForTier(SubscriptionTier::Pro),
        ]);
    }
}
