<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
    @endpush

    <x-slot name="title">Reviews</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Reviews</h1>
                <p class="dph-sub">Approve what hosts send in, and pick which reviews appear on the homepage.</p>
            </div>
            <button type="button" class="btn-primary dash-header-cta" id="openVideoReviewModal">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Add video review
            </button>
        </div>
    </x-slot>

    @php
        // One page, many forms. Only repopulate the form that actually failed
        // validation — otherwise a failed edit would refill every other card.
        $oldFor = function (string $context, string $field, mixed $fallback = null, ?int $id = null): mixed {
            $isFailedForm = old('_form') === $context && (string) old('_review_id') === (string) $id;

            return $isFailedForm ? old($field, $fallback) : $fallback;
        };
    @endphp

    @if (session('status') === 'review-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Review saved.</div>
    @elseif (session('status') === 'review-created')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Video review added.</div>
    @elseif (session('status') === 'review-deleted')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Review deleted.</div>
    @elseif (session('status') === 'review-poster-removed')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Poster image removed.</div>
    @endif

    @if ($errors->any())
        <div class="evt-flash admin-tpl-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <details class="admin-help">
        <summary class="admin-help-summary">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            How this works
            <i class="fa-solid fa-chevron-down admin-help-caret" aria-hidden="true"></i>
        </summary>
        <p class="admin-muted admin-help-body">
            Hosts can review each event they ran once the date has passed. New reviews arrive below as
            <strong>Awaiting review</strong> and are invisible to the public until approved. Approving a review does not
            put it on the homepage — tick <strong>Feature on homepage</strong> as well. The homepage shows up to
            {{ $homepageLimit }} featured reviews in ascending order number, lowest first; right now
            <strong>{{ $featuredCount }}</strong> {{ Str::plural('review', $featuredCount) }}
            {{ $featuredCount === 1 ? 'is' : 'are' }} featured. If a host edits a review it comes straight back here as
            pending and drops off the homepage. Review text is plain text — HTML is escaped, not rendered.
        </p>
    </details>

    @foreach ([
        ['title' => 'Awaiting review', 'items' => $pending, 'empty' => 'Nothing waiting — the queue is clear.'],
        ['title' => 'Published', 'items' => $approved, 'empty' => 'No approved reviews yet.'],
        ['title' => 'Not published', 'items' => $rejected, 'empty' => 'Nothing has been turned down.'],
    ] as $group)
        <div class="admin-section-head admin-mt-lg">
            <h2 class="admin-section-title">{{ $group['title'] }}</h2>
            <span class="admin-muted">{{ $group['items']->count() }} {{ Str::plural('review', $group['items']->count()) }}</span>
        </div>

        @forelse ($group['items'] as $review)
            <article class="admin-panel-card admin-review-card {{ $review->is_featured ? 'is-featured' : '' }}">
                <div class="admin-review-meta">
                    <strong>{{ $review->author_name }}</strong>
                    @if ($review->author_context)
                        <span>{{ $review->author_context }}</span>
                    @endif
                    <span class="admin-review-source">
                        <i class="fa-solid {{ $review->isFromHost() ? 'fa-user' : 'fa-user-shield' }}"></i>
                        {{ $review->isFromHost() ? 'Host' : 'Added by admin' }}
                    </span>
                    @if ($review->event)
                        <span><i class="fa-solid fa-calendar-day"></i> {{ $review->event->name }}</span>
                    @endif
                    <span>{{ $review->created_at?->format('j M Y') }}</span>
                    @if ($review->rating)
                        <span class="admin-review-stars" aria-label="{{ $review->rating }} out of 5 stars">
                            @for ($star = 1; $star <= 5; $star++)
                                <i class="fa-solid fa-star {{ $star <= $review->rating ? '' : 'is-empty' }}" aria-hidden="true"></i>
                            @endfor
                        </span>
                    @endif
                    @if ($review->is_featured)
                        <span class="admin-review-source"><i class="fa-solid fa-star"></i> On the homepage</span>
                    @endif
                </div>

                {{-- The review text is not repeated read-only above; the editable field below is the only copy. --}}
                <form method="post" action="{{ route('admin.reviews.update', $review) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_form" value="edit">
                    <input type="hidden" name="_review_id" value="{{ $review->id }}">

                    @if ($review->isVideo())
                        <div class="admin-review-video">
                            @if ($review->video_poster_url)
                                <img src="{{ $review->video_poster_url }}" alt="" width="160" height="90" class="admin-review-poster">
                            @else
                                <span class="admin-review-poster is-empty"><i class="fa-solid fa-image"></i></span>
                            @endif

                            <div class="admin-review-video-info">
                                @if ($review->videoWatchUrl())
                                    <a href="{{ $review->videoWatchUrl() }}" target="_blank" rel="noopener noreferrer" class="admin-link">
                                        <i class="fa-brands fa-youtube"></i> {{ $review->videoWatchUrl() }}
                                    </a>
                                @else
                                    <span class="admin-muted"><i class="fa-solid fa-triangle-exclamation"></i> No video set — this review cannot be featured.</span>
                                @endif
                            </div>
                        </div>

                        <div class="admin-row-fields">
                            <label class="admin-tpl-field admin-field-wide">
                                <span>Replace the YouTube link <span class="admin-muted">leave blank to keep the current video</span></span>
                                <input type="text" name="video_ref" value="{{ $oldFor('edit', 'video_ref', '', $review->id) }}" maxlength="500"
                                       placeholder="https://www.youtube.com/watch?v=…">
                            </label>

                            <label class="admin-tpl-field admin-tpl-file">
                                <span>Replace the poster image</span>
                                <input type="file" name="video_poster" accept="image/jpeg,image/png,image/webp">
                            </label>
                        </div>
                    @endif

                    <label class="admin-tpl-field admin-field-wide">
                        <span>{{ $review->isVideo() ? 'Quote shown under the video' : 'Review text' }}</span>
                        <textarea name="body" rows="3" maxlength="1500" required>{{ $oldFor('edit', 'body', $review->body, $review->id) }}</textarea>
                    </label>

                    <div class="admin-row-fields">
                        <label class="admin-tpl-field">
                            <span>Name shown</span>
                            <input type="text" name="author_name" value="{{ $oldFor('edit', 'author_name', $review->author_name, $review->id) }}" maxlength="255" required>
                        </label>

                        <label class="admin-tpl-field">
                            <span>Context line</span>
                            <input type="text" name="author_context" value="{{ $oldFor('edit', 'author_context', $review->author_context, $review->id) }}" maxlength="255" placeholder="Wedding · Lusaka">
                        </label>

                        <label class="admin-tpl-field">
                            <span>Rating</span>
                            <input type="number" name="rating" value="{{ $oldFor('edit', 'rating', $review->rating, $review->id) }}" min="1" max="5" step="1">
                        </label>
                    </div>

                    <div class="admin-row-fields">
                        <label class="admin-tpl-field">
                            <span>Status</span>
                            <select name="status" data-cs data-cs-size="sm" required>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->value }}" @selected($oldFor('edit', 'status', $review->status->value, $review->id) === $status->value)>{{ $status->label() }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="admin-tpl-field">
                            <span>Order</span>
                            <input type="number" name="featured_sort_order" value="{{ $oldFor('edit', 'featured_sort_order', $review->featured_sort_order, $review->id) }}" min="0" max="999" step="1" required>
                        </label>

                        <label class="admin-tpl-check admin-faq-check">
                            {{-- Hidden 0 before the checkbox so unticking submits a value; last one wins. --}}
                            <input type="hidden" name="is_featured" value="0">
                            <input type="checkbox" name="is_featured" value="1" @checked((bool) $oldFor('edit', 'is_featured', $review->is_featured, $review->id))>
                            <span>Feature on homepage</span>
                        </label>
                    </div>

                    <label class="admin-tpl-field admin-field-wide">
                        <span>Note to the host (required when not publishing)</span>
                        <textarea name="moderation_note" rows="2" maxlength="500" placeholder="Shown to the host on their reviews page.">{{ $oldFor('edit', 'moderation_note', $review->moderation_note, $review->id) }}</textarea>
                    </label>

                    <div class="admin-actions admin-mt-sm">
                        <button type="submit" class="btn-primary evt-btn-tiny"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                    </div>
                </form>

                {{-- Sibling forms, never nested inside the edit form above. --}}
                <div class="admin-faq-delete admin-actions">
                    @if ($review->isVideo() && $review->video_poster)
                        <form method="post" action="{{ route('admin.reviews.poster.destroy', $review) }}"
                              data-confirm="Remove the poster image? The video itself stays, and the card falls back to a plain play button.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="evt-btn-outline evt-btn-tiny"><i class="fa-solid fa-image-slash"></i> Remove poster</button>
                        </form>
                    @endif

                    <form method="post" action="{{ route('admin.reviews.destroy', $review) }}"
                          data-confirm="Delete this review by {{ $review->author_name }}? This cannot be undone.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="evt-btn-outline evt-btn-tiny admin-tpl-remove"><i class="fa-solid fa-trash"></i> Delete</button>
                    </form>
                </div>
            </article>
        @empty
            <p class="admin-muted">{{ $group['empty'] }}</p>
        @endforelse
    @endforeach

    <div class="profile-modal-overlay" id="videoReviewModalOverlay" role="dialog" aria-modal="true" aria-labelledby="videoReviewModalTitle">
        <div class="profile-modal admin-form-modal">
            <div class="admin-form-modal-head">
                <h2 id="videoReviewModalTitle">Add a video review</h2>
                <button type="button" class="admin-form-modal-close" id="closeVideoReviewModal" aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <p class="admin-muted admin-form-modal-intro">
                Video testimonials are added here by you, never uploaded by hosts. Paste a public or unlisted YouTube link —
                only videos that allow embedding will play. The poster image is the still shown before someone clicks play;
                without one the card falls back to a plain play button. A video review is published as soon as you add it,
                and appears in <strong>Published</strong> below where you can edit or unpublish it like any other.
            </p>

            <form method="post" action="{{ route('admin.reviews.store') }}" enctype="multipart/form-data" class="admin-form-modal-body">
                @csrf
                <input type="hidden" name="_form" value="create">

                <label class="admin-tpl-field admin-field-wide">
                    <span>Quote shown under the video</span>
                    <textarea name="body" rows="3" maxlength="1500" required>{{ $oldFor('create', 'body', '') }}</textarea>
                </label>

                <label class="admin-tpl-field admin-field-wide">
                    <span>YouTube link or video ID</span>
                    <input type="text" name="video_ref" value="{{ $oldFor('create', 'video_ref', '') }}" maxlength="500"
                           placeholder="https://www.youtube.com/watch?v=… or youtu.be/…" required>
                </label>

                <div class="admin-row-fields">
                    <label class="admin-tpl-field">
                        <span>Name shown</span>
                        <input type="text" name="author_name" value="{{ $oldFor('create', 'author_name', '') }}" maxlength="255" required>
                    </label>

                    <label class="admin-tpl-field">
                        <span>Context line</span>
                        <input type="text" name="author_context" value="{{ $oldFor('create', 'author_context', '') }}" maxlength="255" placeholder="Wedding · Lusaka">
                    </label>

                    <label class="admin-tpl-field">
                        <span>Rating <span class="admin-muted">optional</span></span>
                        <input type="number" name="rating" value="{{ $oldFor('create', 'rating', '') }}" min="1" max="5" step="1">
                    </label>

                    <label class="admin-tpl-field">
                        <span>Order</span>
                        <input type="number" name="featured_sort_order" value="{{ $oldFor('create', 'featured_sort_order', 0) }}" min="0" max="999" step="1" required>
                    </label>

                    <label class="admin-tpl-check admin-faq-check">
                        <input type="hidden" name="is_featured" value="0">
                        <input type="checkbox" name="is_featured" value="1" @checked((bool) $oldFor('create', 'is_featured', false))>
                        <span>Feature on homepage</span>
                    </label>
                </div>

                <div class="admin-row-fields">
                    <label class="admin-tpl-field admin-tpl-file">
                        <span>Poster image <span class="admin-muted">optional, cropped to 16:9</span></span>
                        <input type="file" name="video_poster" accept="image/jpeg,image/png,image/webp">
                    </label>

                    <label class="admin-tpl-field admin-tpl-file">
                        <span>Photo of the reviewer <span class="admin-muted">optional, cropped square</span></span>
                        <input type="file" name="author_photo" accept="image/jpeg,image/png,image/webp">
                    </label>
                </div>

                <div class="admin-form-modal-actions">
                    <button type="button" class="profile-modal-cancel" id="cancelVideoReviewModal">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add video review</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        const overlay = document.getElementById('videoReviewModalOverlay');
        const openBtn = document.getElementById('openVideoReviewModal');
        const closeBtn = document.getElementById('closeVideoReviewModal');
        const cancelBtn = document.getElementById('cancelVideoReviewModal');
        if (!overlay || !openBtn) return;

        function open() { overlay.classList.add('is-open'); }
        function close() { overlay.classList.remove('is-open'); }

        openBtn.addEventListener('click', open);
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (cancelBtn) cancelBtn.addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

        @if (old('_form') === 'create')
            open();
        @endif
    }());
    </script>
</x-admin-layout>
