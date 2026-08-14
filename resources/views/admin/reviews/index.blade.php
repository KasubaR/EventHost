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
    @elseif (session('status') === 'review-deleted')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Review deleted.</div>
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

    <div class="admin-panel-card">
        <h2>How this works</h2>
        <p class="admin-muted">
            Hosts can review each event they ran once the date has passed. New reviews arrive below as
            <strong>Awaiting review</strong> and are invisible to the public until approved. Approving a review does not
            put it on the homepage — tick <strong>Feature on homepage</strong> as well. The homepage shows up to
            {{ $homepageLimit }} featured reviews in ascending order number, lowest first; right now
            <strong>{{ $featuredCount }}</strong> {{ Str::plural('review', $featuredCount) }}
            {{ $featuredCount === 1 ? 'is' : 'are' }} featured. If a host edits a review it comes straight back here as
            pending and drops off the homepage. Review text is plain text — HTML is escaped, not rendered.
        </p>
    </div>

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
                <form method="post" action="{{ route('admin.reviews.update', $review) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_form" value="edit">
                    <input type="hidden" name="_review_id" value="{{ $review->id }}">

                    <label class="admin-tpl-field admin-field-wide">
                        <span>Review text</span>
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

                <form method="post" action="{{ route('admin.reviews.destroy', $review) }}" class="admin-faq-delete"
                      data-confirm="Delete this review by {{ $review->author_name }}? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="evt-btn-outline evt-btn-tiny admin-tpl-remove"><i class="fa-solid fa-trash"></i> Delete</button>
                </form>
            </article>
        @empty
            <p class="admin-muted">{{ $group['empty'] }}</p>
        @endforelse
    @endforeach
</x-admin-layout>
