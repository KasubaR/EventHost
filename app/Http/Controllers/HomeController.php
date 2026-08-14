<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Number of events in the homepage strip. The section is hidden entirely
     * when nothing qualifies — see home.blade.php.
     */
    private const HOMEPAGE_LIMIT = 6;

    public function index(): View
    {
        $upcomingEvents = Event::query()
            ->publiclyListed()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(self::HOMEPAGE_LIMIT)
            ->get();

        return view('home', compact('upcomingEvents'));
    }
}
