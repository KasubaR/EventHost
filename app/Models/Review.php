<?php

namespace App\Models;

use App\Enums\ReviewMediaType;
use App\Enums\ReviewStatus;
use App\Support\InvitationVideoBackground;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * Who wrote the review. Hosts submit from their portal; admins author video
     * testimonials directly. Kept explicit rather than inferred from a null
     * user_id so validation and the admin filters can key on it.
     */
    public const SOURCE_USER = 'user';

    public const SOURCE_ADMIN = 'admin';

    /**
     * Number of reviews in the homepage strip — two rows of the 3-up
     * `.testi-grid`. The section is hidden entirely when nothing is featured,
     * see home.blade.php.
     */
    public const HOMEPAGE_FEATURED_LIMIT = 6;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'source',
        'media_type',
        'rating',
        'body',
        'author_name',
        'author_context',
        'author_photo',
        'video_ref',
        'video_poster',
        'status',
        'moderation_note',
        'is_featured',
        'featured_sort_order',
        'approved_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Reviews to render in the homepage strip (and the login sidebar quote),
     * in admin-defined order.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeFeaturedForHomepage(Builder $query): void
    {
        $query->where('status', ReviewStatus::Approved)
            ->where('is_featured', true)
            // A video review with no video cannot go on the homepage. Also
            // enforced in UpdateReviewRequest — same belt-and-braces treatment
            // as "a template cannot be featured without an image".
            ->where(function (Builder $inner): void {
                $inner->where('media_type', '!=', ReviewMediaType::Video->value)
                    ->orWhere(function (Builder $video): void {
                        $video->whereNotNull('video_ref')->where('video_ref', '!=', '');
                    });
            })
            ->orderBy('featured_sort_order')
            ->orderBy('id');
    }

    public function isVideo(): bool
    {
        return $this->media_type === ReviewMediaType::Video;
    }

    public function isFromHost(): bool
    {
        return $this->source === self::SOURCE_USER;
    }

    /**
     * `author_photo` is snapshotted from the host's profile photo at submit time
     * (or uploaded by an admin), so rendering the strip never has to touch the
     * users table.
     */
    public function getAuthorPhotoUrlAttribute(): string
    {
        $path = $this->author_photo;

        // Guard: author_photo is a storage-relative path, never a full URL.
        // Mirrors Event::getCoverImageUrlAttribute().
        if (is_string($path) && $path !== '' && ! str_contains($path, '://')) {
            return asset('storage/'.$path);
        }

        return asset('images/default-avatar.png');
    }

    /**
     * Poster still for a video review, or null when none was uploaded — the
     * homepage then falls back to a plain play button.
     */
    public function getVideoPosterUrlAttribute(): ?string
    {
        $path = $this->video_poster;

        if (is_string($path) && $path !== '' && ! str_contains($path, '://')) {
            return asset('storage/'.$path);
        }

        return null;
    }

    /**
     * Click-to-play embed, or null when the stored reference is missing or
     * malformed. Nothing renders an iframe until the viewer asks for it.
     */
    public function videoEmbedUrl(): ?string
    {
        $id = InvitationVideoBackground::extractIdFromStored($this->video_ref);

        return $id !== null ? InvitationVideoBackground::playerEmbedUrl($id) : null;
    }

    /**
     * Share URL for the admin panel, so the stored `youtube:<id>` value round
     * trips back into the field the admin pasted into.
     */
    public function videoWatchUrl(): ?string
    {
        return InvitationVideoBackground::watchUrlFromStored($this->video_ref);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'event_id' => 'integer',
            'media_type' => ReviewMediaType::class,
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'is_featured' => 'boolean',
            'featured_sort_order' => 'integer',
            'approved_at' => 'datetime',
        ];
    }
}
