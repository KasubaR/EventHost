<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReviewMediaType;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\Api\V1\ReviewResource;
use App\Models\Event;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * JSON sibling of App\Http\Controllers\ReviewController (Slice E).
 * authorizeResource() works identically under Sanctum (proven already by
 * Api\V1\EventController in Slice C1). Reuses StoreReviewRequest/
 * UpdateReviewRequest verbatim.
 */
class ReviewController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Review::class, 'review');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $events = $request->user()
            ->events()
            ->with('review')
            ->whereDate('event_date', '<', today())
            ->orderByDesc('event_date')
            ->get();

        // The web index shows every past event with the review form or its
        // current status inline; the API returns just the reviews that
        // already exist — the app builds the "not yet reviewed" affordance
        // for the rest from its own event list (Slice C's /host/events),
        // which already flags isReviewable-equivalent state.
        return ReviewResource::collection(
            $events->pluck('review')->filter()->values()
        );
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        /** @var Event $event */
        $event = Event::query()->findOrFail($data['event_id']);

        $review = Review::query()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'source' => Review::SOURCE_USER,
            'media_type' => ReviewMediaType::Text,
            'rating' => $data['rating'],
            'body' => $data['body'],
            'author_name' => $user->name,
            'author_context' => $event->reviewAuthorContext(),
            'author_photo' => $user->profile_photo,
            'status' => ReviewStatus::Pending,
        ]);

        return response()->json(['review' => new ReviewResource($review)], 201);
    }

    public function update(UpdateReviewRequest $request, Review $review): JsonResponse
    {
        $data = $request->validated();

        $review->update([
            'rating' => $data['rating'],
            'body' => $data['body'],
            'status' => ReviewStatus::Pending,
            'is_featured' => false,
            'approved_at' => null,
            'moderation_note' => null,
        ]);

        return response()->json(['review' => new ReviewResource($review)]);
    }

    public function destroy(Review $review): JsonResponse
    {
        $review->delete();

        return response()->json(['message' => 'Review deleted.']);
    }
}
