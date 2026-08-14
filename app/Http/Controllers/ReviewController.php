<?php

namespace App\Http\Controllers;

use App\Enums\ReviewMediaType;
use App\Enums\ReviewStatus;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Models\Event;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Review::class, 'review');
    }

    public function index(Request $request): View
    {
        // Past events only — a review is a look back at how the day went. Each
        // carries its review if one exists, so the view can show the submission
        // form or the current status without a second query per row.
        $events = $request->user()
            ->events()
            ->with('review')
            ->whereDate('event_date', '<', today())
            ->orderByDesc('event_date')
            ->get();

        return view('reviews.index', compact('events'));
    }

    public function store(StoreReviewRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        /** @var Event $event */
        $event = Event::query()->findOrFail($data['event_id']);

        Review::query()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'source' => Review::SOURCE_USER,
            'media_type' => ReviewMediaType::Text,
            'rating' => $data['rating'],
            'body' => $data['body'],
            // Snapshotted at submit time — see the Review model.
            'author_name' => $user->name,
            'author_context' => $event->reviewAuthorContext(),
            'author_photo' => $user->profile_photo,
            'status' => ReviewStatus::Pending,
        ]);

        return redirect()
            ->route('reviews.index')
            ->with('status', 'review-submitted');
    }

    public function update(UpdateReviewRequest $request, Review $review): RedirectResponse
    {
        $data = $request->validated();

        // An edited review goes back through moderation. Without this a host
        // could get a mild review approved and featured, then rewrite it into
        // anything at all on a live homepage.
        $review->update([
            'rating' => $data['rating'],
            'body' => $data['body'],
            'status' => ReviewStatus::Pending,
            'is_featured' => false,
            'approved_at' => null,
            'moderation_note' => null,
        ]);

        return redirect()
            ->route('reviews.index')
            ->with('status', 'review-updated');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $review->delete();

        return redirect()
            ->route('reviews.index')
            ->with('status', 'review-deleted');
    }
}
