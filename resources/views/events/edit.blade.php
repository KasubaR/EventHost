<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
        <link rel="stylesheet" href="{{ asset('css/media-uploader.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/events-form.js') }}" defer></script>
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
        <script src="{{ asset('js/vendor/sortable.min.js') }}" defer></script>
        <script src="{{ asset('js/invitation-customize.js') }}" defer></script>
        {{-- Before event-edit-save.js: saving waits on window.MediaUploader. --}}
        <script src="{{ asset('js/media-uploader.js') }}" defer></script>
        <script src="{{ asset('js/event-edit-save.js') }}" defer></script>
    @endpush

    <x-slot name="title">Edit event</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Edit Event</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                @if ($event->isTicketed())
                    <a href="{{ route('public-events.ticket-types.index', $event) }}" class="evt-btn-outline"><x-ticket-icon /> Back to tickets</a>
                @endif
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-eye"></i> View</a>
                <a href="{{ route($event->isPublicAudience() ? 'public-events.index' : 'events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-list"></i> All events</a>
            </div>
        </div>
    </x-slot>

    @include('events.partials.steps', [
        'current' => 4,
        'ticketed' => $event->isTicketed(),
    ])

    @if (session('status') === 'draft-saved')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Draft saved — continue editing or publish below.</div>
    @endif

    @if (session('status') === 'event-updated')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event updated.</div>

        @if (session('notify_guests_count', 0) > 0)
            @php $notifyCount = session('notify_guests_count'); @endphp
            <div class="evt-flash evt-flash--warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                You changed the venue or location. {{ $notifyCount }} {{ $notifyCount === 1 ? 'guest' : 'guests' }}
                already {{ $notifyCount === 1 ? 'has' : 'have' }} an invitation or RSVP for this event and
                will not be told automatically —
                <a href="{{ route('events.guests.index', $event) }}">notify them from the guest list</a>.
            </div>
        @endif
    @endif

    @if (session('status') === 'invitation-design-saved')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Invitation design saved.</div>
    @endif

    @if (session('status') === 'template-chosen')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Invitation layout saved — customize below.</div>
    @endif

    @if (session('status') === 'public-registration-submitted')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Submitted for review — EventHost will approve and quote a price soon.</div>
    @endif

    @if ($errors->has('public_registration'))
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first('public_registration') }}</div>
    @endif

    @if ($event->isLocked())
        <div class="evt-flash evt-flash--warn">
            <i class="fa-solid fa-circle-info"></i>
            This event has already taken place. Changing its <strong>name, type or date</strong> makes it a
            new event and uses 1 credit — you have {{ auth()->user()->event_credits }}. Everything else
            (time, venue, description, cover image and settings) is still free to change.
        </div>
    @endif

    <div class="evt-stack">
        <form id="event-update-form" method="post" action="{{ route('events.update', $event) }}" enctype="multipart/form-data" class="profile-form">
            @csrf
            @method('patch')
            @include('events.partials.form-fields', ['event' => $event])

            <div class="evt-section-body evt-actions-bar evt-per-form-actions">
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save changes
                </button>
            </div>
        </form>

        @unless ($event->isTicketed())
            @if ($event->invitation_template_id === null)
                <div class="evt-section evt-section-prompt">
                    <div class="evt-section-head">
                        <h2>Invitation Layout</h2>
                        <p>Pick a template to unlock colors, typography, gallery, and section controls.</p>
                    </div>
                    <div class="evt-section-body evt-actions-bar">
                        <a href="{{ route('events.choose-template', $event) }}" class="btn-primary">
                            <i class="fa-solid fa-layer-group"></i> Choose invitation layout
                        </a>
                        <span class="evt-muted">Required before guests see your styled invitation.</span>
                    </div>
                </div>
            @else
                @include('events.partials.invitation-design-form', ['event' => $event, 'invitationMerged' => $invitationMerged])

                <p class="evt-muted evt-template-switch-note">
                    <a href="{{ route('events.choose-template', $event) }}">Switch to a different layout</a>
                    <span aria-hidden="true"> · </span>
                    Fine-tuning resets some layout-specific section defaults until you save design again.
                </p>

                {{-- Moved below the template picker (rather than living in
                     form-fields.blade.php with the rest of the event details)
                     because whether a cover image is even worth collecting
                     depends on the chosen layout: Modern Minimal and Event
                     Invite never render $event->cover_image_url in their hero,
                     so the field is hidden entirely for them here. The input
                     still posts with the main details form via
                     form="event-update-form" — see cover-image-field.blade.php. --}}
                @if (\App\Support\InvitationLayoutVariant::usesCoverImage($event->invitationTemplate?->layout_variant))
                    @include('events.partials.cover-image-field', ['event' => $event, 'associateForm' => true])
                @endif
            @endif
        @endunless

        @if ($event->invitation_template_id !== null)
            <div class="evt-section" id="evt-preview-cta">
                <div class="evt-section-body evt-actions-bar">
                    <a href="{{ route('events.preview', $event) }}" target="_blank" rel="noopener" class="evt-btn-outline" id="evt-preview-link" data-preview-link>
                        <i class="fa-solid fa-eye"></i> Preview invitation
                    </a>
                    <span class="evt-muted">Opens in a new tab — see exactly what guests will see before you publish.</span>
                </div>
            </div>
        @endif

        {{-- Single action bar. Without JS these fall back to the per-form buttons above,
             which stay visible because evt-save-all.js is what hides them. --}}
        <div class="evt-section evt-save-all" id="evt-save-all-bar"
             data-publish-url="{{ route('events.publish', $event) }}"
             data-public-url="{{ route('events.public', $event->slug) }}"
             @if ($publishCostsCredit)
                 data-publish-confirm="Publishing uses 1 event credit. Continue?"
             @endif
             @if ($event->isLocked())
                 data-redefine-confirm="This event has already taken place. If you changed its name, type or date, saving will use 1 event credit. Continue?"
             @endif>
            <div class="evt-section-body evt-actions-bar">
                <button type="button" class="btn-primary" data-save-all>
                    <i class="fa-solid fa-floppy-disk"></i> Save Draft
                </button>
                @if ($event->isTicketed())
                    <span class="evt-muted">Ticketed events go live after EventHost activates sales — they do not use event credits.</span>
                @elseif ($event->isFreeRegistration())
                    <span class="evt-muted">Public events go live after EventHost approves them and you pay the quoted amount — they do not use event credits.</span>
                @elseif (! $event->is_published)
                    <button type="button" class="btn-primary" data-save-all data-publish data-requires-preview>
                        <i class="fa-solid fa-bullhorn"></i> Save &amp; publish
                    </button>
                    <span class="evt-muted evt-flash--warn" data-preview-required-hint hidden>
                        <i class="fa-solid fa-eye"></i> Preview your invitation above first.
                    </span>
                    @if ($publishCostsCredit)
                        <span class="evt-muted">Publishing uses 1 event credit — you have {{ auth()->user()->event_credits }}.</span>
                    @else
                        <span class="evt-muted">Publishing saves everything first, then makes the invitation public.</span>
                    @endif
                @else
                    <span class="evt-muted">Saves your event details and invitation design together.</span>
                @endif
            </div>
        </div>

        {{-- Custom confirm dialog for "Save & publish", same vanilla-JS overlay
             pattern as the account delete modal
             (settings/partials/delete-account-form.blade.php) and the ticket
             activation modal — accent icon since this confirms a normal
             forward action, not something destructive. Always rendered;
             event-edit-save.js only opens it when the bar carries a
             data-publish-confirm message (i.e. $publishCostsCredit above), so
             a free publish (already-consumed credit) still skips it exactly
             as before. --}}
        <div class="profile-modal-overlay" id="publishConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="publishConfirmTitle">
            <div class="profile-modal">
                <div class="profile-modal-header">
                    <div class="profile-modal-icon profile-modal-icon--accent"><i class="fa-solid fa-bullhorn"></i></div>
                    <h3 id="publishConfirmTitle">Publish this event?</h3>
                    <p id="publishConfirmMessage"></p>
                </div>
                <div class="profile-modal-actions">
                    <button type="button" class="profile-modal-cancel" id="publishConfirmCancel">Cancel</button>
                    <button type="button" class="btn-primary" id="publishConfirmYes">
                        <i class="fa-solid fa-bullhorn"></i> Yes, Publish
                    </button>
                </div>
            </div>
        </div>

        @if ($event->isTicketed())
            @include('events.tickets.partials.rejection-note', ['event' => $event])
            @include('events.tickets.partials.activation-panel', ['event' => $event, 'ticketTypes' => $ticketTypes])
        @elseif ($event->isFreeRegistration() && ! $event->is_published)
            {{-- Payment (Step 2) and the submit-for-review action (Step 3) of
                 plans/public-private-portals.md Phase 4c are both live now.
                 Admin approval itself still only happens through the admin
                 panel card on admin/events/show.blade.php.

                 Plain evt-section, not evt-per-form-actions: that class is
                 event-edit-save.js's signal to hide a no-JS-fallback button
                 once the unified save bar provides the same action (see its
                 own docblock) — none of the actions below have a JS
                 equivalent in that bar, so hiding them here would remove the
                 host's only way to reach them. Same reasoning as the ticketed
                 activation panel just above, which uses plain evt-section
                 too. --}}
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>Publish</h2>
                    <p>Public events are approved and priced by EventHost, not published with an event credit.</p>
                </div>
                <div class="evt-section-body">
                    @if ($event->publicRegistrationApproved())
                        <p class="evt-muted">
                            EventHost approved this event and quoted
                            <strong>K{{ number_format((float) $event->public_registration_quote_amount, 2) }}</strong>.
                            Pay to make the invitation live.
                        </p>
                        <div class="evt-card-actions">
                            <a href="{{ route('events.public-registration.pay', $event) }}" class="btn-primary">
                                <i class="fa-solid fa-credit-card"></i> Pay K{{ number_format((float) $event->public_registration_quote_amount, 0) }} to publish
                            </a>
                        </div>
                    @elseif ($event->public_registration_status === \App\Enums\PublicRegistrationStatus::PendingReview)
                        <p class="evt-muted">
                            Submitted {{ $event->public_registration_submitted_at?->format('j M Y, H:i') }}.
                            The invitation stays off until EventHost approves it.
                        </p>
                    @else
                        @if ($event->public_registration_status === \App\Enums\PublicRegistrationStatus::Rejected && $event->public_registration_rejection_note)
                            <div class="evt-flash evt-flash--warn">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                EventHost declined this event: {{ $event->public_registration_rejection_note }}
                            </div>
                        @endif
                        <p class="evt-muted">
                            Save your event details and design above, then submit it for review. EventHost will
                            approve it and quote a price — the invitation goes live once you pay that quote.
                        </p>
                        <form method="post" action="{{ route('events.public-registration.submit', $event) }}" class="evt-card-actions">
                            @csrf
                            <button type="submit" class="btn-primary">
                                <i class="fa-solid fa-paper-plane"></i> Submit for review
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @elseif (! $event->is_published)
            <div class="evt-section evt-per-form-actions">
                <div class="evt-section-head">
                    <h2>Publish</h2>
                    <p>Make this invitation visible at its public link.</p>
                </div>
                <div class="evt-section-body evt-actions-bar">
                    {{-- Submits the event form above so pending edits are saved and published together --}}
                    <button type="submit" form="event-update-form" name="publish" value="1" class="btn-primary">
                        <i class="fa-solid fa-bullhorn"></i> Publish event
                    </button>
                    @if ($publishCostsCredit)
                        <span class="evt-muted">Uses 1 event credit, then makes the invitation public.</span>
                    @else
                        <span class="evt-muted">Saves your event details, then makes the invitation public.</span>
                    @endif
                </div>
            </div>
        @else
            <div class="evt-section">
                @if ($event->is_public)
                    <div class="evt-section-head">
                        <h2>Share</h2>
                        <p>Your live invitation link.</p>
                    </div>
                    <div class="evt-section-body">
                        <a href="{{ route('events.public', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug) }}</a>
                    </div>
                @else
                    <div class="evt-section-head">
                        <h2>Share</h2>
                        <p>This is a private event, so it has no public link.</p>
                    </div>
                    <div class="evt-section-body">
                        <span class="evt-muted">Send each guest their own invite from <a href="{{ route('events.guests.index', $event) }}">Guests</a> — that personal link is how they view and RSVP.</span>
                    </div>
                @endif
            </div>
        @endif

        <div class="profile-card profile-card--danger">
            <div class="profile-card-header">
                <div class="profile-card-icon" aria-hidden="true"><i class="fa-solid fa-trash"></i></div>
                <div>
                    <h3>Delete Event</h3>
                    <p>Removes the event from your list. Guests see “Invitation no longer available”. You can restore it later.</p>
                </div>
            </div>
            <div class="profile-form">
                <form method="post" action="{{ route('events.destroy', $event) }}" data-confirm="Delete this event? Guests will see that the invitation is no longer available. You can restore it from My Events.">
                    @csrf
                    @method('delete')
                    <button type="submit" class="evt-btn-outline evt-btn-danger-outline">
                        <i class="fa-solid fa-trash"></i> Delete event
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
