<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewMediaType;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReviewRequest;
use App\Http\Requests\Admin\UpdateReviewRequest;
use App\Models\Review;
use App\Support\AdminActivity;
use App\Support\InvitationVideoBackground;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Intervention\Image\ImageManager;

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

    /**
     * Admin-authored video reviews. There is no user-facing equivalent — hosts
     * only ever submit text.
     */
    public function store(StoreReviewRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $review = Review::query()->create([
            'user_id' => null,
            'event_id' => null,
            'source' => Review::SOURCE_ADMIN,
            'media_type' => ReviewMediaType::Video,
            'rating' => $data['rating'] ?? null,
            'body' => $data['body'],
            'author_name' => $data['author_name'],
            'author_context' => $data['author_context'] ?? null,
            'author_photo' => $this->storeUpload($request->file('author_photo'), 88, 88, 'avatar_'),
            'video_ref' => InvitationVideoBackground::normalizeUserInput($data['video_ref']),
            'video_poster' => $this->storeUpload($request->file('video_poster'), 640, 360, 'poster_'),
            // The admin is the moderator, so their own review needs no queue.
            'status' => ReviewStatus::Approved,
            'approved_at' => now(),
            'is_featured' => (bool) $data['is_featured'],
            'featured_sort_order' => (int) $data['featured_sort_order'],
        ]);

        AdminActivity::log('Admin created a video review', [
            'review_id' => $review->id,
            'is_featured' => $review->is_featured,
        ]);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'review-created');
    }

    public function update(UpdateReviewRequest $request, Review $review): RedirectResponse
    {
        $data = $request->validated();
        $status = ReviewStatus::from($data['status']);
        $previousPoster = $review->video_poster;
        $newPoster = null;

        if ($review->isVideo()) {
            $newPoster = $this->storeUpload($request->file('video_poster'), 640, 360, 'poster_');

            // Blank means "leave the current video alone" — the admin is most
            // often here to fix wording, not to re-paste the same link.
            $normalized = InvitationVideoBackground::normalizeUserInput($data['video_ref'] ?? null);
            if ($normalized !== null) {
                $review->video_ref = $normalized;
            }

            if ($newPoster !== null) {
                $review->video_poster = $newPoster;
            }
        }

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

        // Only drop the old file once the new path is safely committed.
        if ($newPoster !== null && $previousPoster !== null && $previousPoster !== '') {
            DB::afterCommit(function () use ($previousPoster): void {
                Storage::disk('public')->delete($previousPoster);
            });
        }

        AdminActivity::log('Admin moderated a review', [
            'review_id' => $review->id,
            'status' => $status->value,
            'is_featured' => $review->is_featured,
            'poster_replaced' => $newPoster !== null,
        ]);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'review-updated');
    }

    /**
     * A poster is optional decoration — unlike an invitation template's image,
     * dropping it does not unfeature the review. The video is the requirement.
     */
    public function destroyPoster(Review $review): RedirectResponse
    {
        $previousPoster = $review->video_poster;

        $review->video_poster = null;
        $review->save();

        if ($previousPoster !== null && $previousPoster !== '') {
            DB::afterCommit(function () use ($previousPoster): void {
                Storage::disk('public')->delete($previousPoster);
            });
        }

        AdminActivity::log('Admin removed a review poster image', [
            'review_id' => $review->id,
        ]);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'review-poster-removed');
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

    /**
     * Same shape as InvitationTemplateController::storePreviewImage() — Imagick
     * when it is loaded, GD otherwise, cropped to a fixed box and re-encoded to
     * WebP on the public disk.
     */
    private function storeUpload(?UploadedFile $file, int $width, int $height, string $prefix): ?string
    {
        if ($file === null) {
            return null;
        }

        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();

        $image = $manager->read($file->getRealPath());
        $image->cover($width, $height);

        $path = 'reviews/'.uniqid($prefix, true).'.webp';
        Storage::disk('public')->put($path, $image->toWebp(85)->toString());

        return $path;
    }
}
