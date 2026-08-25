<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Faq;
use App\Models\InvitationTemplate;
use App\Models\Review;
use App\Services\PopularBillingPlanResolver;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Number of events in the homepage strip. The section is hidden entirely
     * when nothing qualifies — see home.blade.php.
     */
    private const HOMEPAGE_LIMIT = 6;

    public function index(PopularBillingPlanResolver $popularPlans): View
    {
        $upcomingEvents = Event::query()
            ->publiclyListed()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(self::HOMEPAGE_LIMIT)
            ->get();

        // Curated in the admin panel. The section is hidden when nothing is
        // featured — see home.blade.php.
        $featuredTemplates = InvitationTemplate::query()
            ->featuredForHomepage()
            ->with('categories')
            ->limit(InvitationTemplate::HOMEPAGE_FEATURED_LIMIT)
            ->get();

        // Managed in the admin panel. The section is hidden when nothing is
        // published — see home.blade.php.
        $homepageFaqs = Faq::query()->publishedFor('homepage')->get();

        // Approved host reviews the admin has chosen to feature. Hidden when
        // nothing qualifies — see home.blade.php.
        $featuredReviews = Review::query()
            ->featuredForHomepage()
            ->limit(Review::HOMEPAGE_FEATURED_LIMIT)
            ->get();

        // Backs the "N premium templates" pricing-card copy — see home.blade.php.
        $activeTemplateCount = InvitationTemplate::activeCount();

        $popularPlanKey = $popularPlans->resolve();

        return view('home', compact(
            'upcomingEvents',
            'featuredTemplates',
            'homepageFaqs',
            'featuredReviews',
            'activeTemplateCount',
            'popularPlanKey',
        ));
    }
}
