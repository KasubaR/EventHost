<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/reviews.css') }}">
    @endpush

    @push('scripts')
        {{-- Generic form[data-confirm] handler despite the filename. --}}
        <script src="{{ asset('js/admin-confirm.js') }}" defer></script>
    @endpush

    <x-slot name="title">My reviews</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">My reviews</h1>
                <p class="dph-sub">Tell us how your events went. Approved reviews may be featured on our homepage.</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> My events</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'review-submitted')
        <div class="evt-admin-flash">Thanks — your review has been sent for approval.</div>
    @elseif (session('status') === 'review-updated')
        <div class="evt-admin-flash">Review saved. It will be checked again before it goes back on the site.</div>
    @elseif (session('status') === 'review-deleted')
        <div class="evt-admin-flash">Review removed.</div>
    @endif

    @if ($errors->any())
        <div class="evt-admin-flash rev-flash-error" role="alert">
            <ul class="rev-error-list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="evt-stack">
        @forelse ($events as $event)
            @php $review = $event->review; @endphp

            <article class="evt-section">
                <div class="evt-section-head">
                    <h2>{{ $event->name }}</h2>
                    <p>{{ $event->event_type_label }} · {{ $event->event_date?->format('j M Y') }}</p>
                </div>

                <div class="evt-section-body">
                    @if ($review !== null)
                        <div class="rev-status-row">
                            <span class="rev-pill rev-pill-{{ $review->status->value }}">{{ $review->status->label() }}</span>
                            @if ($review->is_featured)
                                <span class="rev-pill rev-pill-featured"><i class="fa-solid fa-star"></i> Featured on the homepage</span>
                            @endif
                        </div>

                        @if ($review->moderation_note)
                            <p class="rev-moderation-note">
                                <i class="fa-solid fa-circle-info"></i> {{ $review->moderation_note }}
                            </p>
                        @endif

                        <form method="post" action="{{ route('reviews.update', $review) }}" class="profile-form-stack rev-form">
                            @csrf
                            @method('PATCH')

                            <div class="profile-field">
                                <span class="profile-label">Your rating</span>
                                @include('reviews.partials.rating-input', ['name' => 'rating', 'id' => 'review-'.$review->id, 'value' => $review->rating])
                            </div>

                            <div class="profile-field">
                                <label for="body-{{ $review->id }}" class="profile-label">Your review</label>
                                <textarea id="body-{{ $review->id }}" name="body" rows="4" minlength="20" maxlength="1500" required
                                          class="profile-input rev-textarea">{{ old('body', $review->body) }}</textarea>
                            </div>

                            <p class="evt-muted rev-hint">
                                Saving an edit sends the review back for approval, and takes it off the homepage until it is approved again.
                            </p>

                            <div class="profile-actions rev-actions">
                                <button type="submit" class="btn-primary">Save changes</button>
                            </div>
                        </form>

                        <form method="post" action="{{ route('reviews.destroy', $review) }}" class="rev-delete-form"
                              data-confirm="Delete your review of “{{ $event->name }}”? This cannot be undone.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="evt-btn-danger-outline evt-btn-tiny"><i class="fa-solid fa-trash"></i> Delete review</button>
                        </form>
                    @elseif ($event->isReviewable())
                        <form method="post" action="{{ route('reviews.store') }}" class="profile-form-stack rev-form">
                            @csrf
                            <input type="hidden" name="event_id" value="{{ $event->id }}">

                            <div class="profile-field">
                                <span class="profile-label">Your rating</span>
                                @include('reviews.partials.rating-input', ['name' => 'rating', 'id' => 'new-'.$event->id, 'value' => null])
                            </div>

                            <div class="profile-field">
                                <label for="new-body-{{ $event->id }}" class="profile-label">Your review</label>
                                <textarea id="new-body-{{ $event->id }}" name="body" rows="4" minlength="20" maxlength="1500" required
                                          class="profile-input rev-textarea"
                                          placeholder="How did the invitations, RSVPs and the day itself go?"></textarea>
                            </div>

                            <div class="profile-actions rev-actions">
                                <button type="submit" class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit review</button>
                            </div>
                        </form>
                    @else
                        <p class="evt-muted">
                            This event was never published, so it cannot be reviewed.
                        </p>
                    @endif
                </div>
            </article>
        @empty
            <div class="evt-section">
                <div class="evt-section-body rev-empty">
                    <i class="fa-solid fa-star rev-empty-icon"></i>
                    <h2>No events to review yet</h2>
                    <p class="evt-muted">
                        Once one of your events has taken place, it shows up here and you can tell us how it went.
                    </p>
                    <a href="{{ route('events.index') }}" class="btn-primary">Go to my events</a>
                </div>
            </div>
        @endforelse
    </div>
</x-app-layout>
