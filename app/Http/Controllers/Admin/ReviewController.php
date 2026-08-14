<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReviewRequest;
use App\Models\Review;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(): View
    {
        $pending = Review::query()
            ->with('event')
            ->where('status', ReviewStatus::Pending)
            ->orderBy('created_at')
            ->get();

        $approved = Review::query()
            ->with('event')
            ->where('status', ReviewStatus::Approved)
            ->orderByDesc('is_featured')
            ->orderBy('featured_sort_order')
            ->orderBy('id')
            ->get();

        $rejected = Review::query()
            ->with('event')
            ->where('status', ReviewStatus::Rejected)
            ->orderByDesc('updated_at')
            ->get();

        return view('admin.reviews.index', [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'featuredCount' => $approved->where('is_featured', true)->count(),
            'homepageLimit' => Review::HOMEPAGE_FEATURED_LIMIT,
            'statuses' => ReviewStatus::cases(),
        ]);
    }

    public function update(UpdateReviewRequest $request, Review $review): RedirectResponse
    {
        $data = $request->validated();
        $status = ReviewStatus::from($data['status']);

        $review->fill([
            'status' => $status,
            'moderation_note' => $status === ReviewStatus::Rejected ? $data['moderation_note'] : null,
            'body' => $data['body'],
            'rating' => $data['rating'],
            'author_name' => $data['author_name'],
            'author_context' => $data['author_context'],
            // Only an approved review can sit on the homepage. Coerced rather
            // than rejected so that unpublishing a featured review is one click
            // instead of a validation error about a checkbox.
            'is_featured' => $status === ReviewStatus::Approved && (bool) $data['is_featured'],
            'featured_sort_order' => (int) $data['featured_sort_order'],
        ]);

        if ($status === ReviewStatus::Approved) {
            $review->approved_at ??= now();
        } else {
            $review->approved_at = null;
        }

        $review->save();

        AdminActivity::log('Admin moderated a review', [
            'review_id' => $review->id,
            'status' => $status->value,
            'is_featured' => $review->is_featured,
        ]);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'review-updated');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $id = $review->id;
        $source = $review->source;

        $review->delete();

        AdminActivity::log('Admin deleted a review', [
            'review_id' => $id,
            'source' => $source,
        ]);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'review-deleted');
    }
}
