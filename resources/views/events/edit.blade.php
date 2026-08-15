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
                <h1 class="dph-title">Edit event</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-eye"></i> View</a>
                <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-list"></i> All events</a>
            </div>
        </div>
    </x-slot>

    @include('events.partials.steps', ['current' => 3])

    @if (session('status') === 'draft-saved')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Draft saved — continue editing or publish below.</div>
    @endif

    @if (session('status') === 'event-updated')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event updated.</div>
    @endif

    @if (session('status') === 'invitation-design-saved')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Invitation design saved.</div>
    @endif

    @if (session('status') === 'template-chosen')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Invitation layout saved — customize below.</div>
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

        @if ($event->invitation_template_id === null)
            <div class="evt-section evt-section-prompt">
                <div class="evt-section-head">
                    <h2>Invitation layout</h2>
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
        @endif

        {{-- Single action bar. Without JS these fall back to the per-form buttons above,
             which stay visible because evt-save-all.js is what hides them. --}}
        <div class="evt-section evt-save-all" id="evt-save-all-bar"
             data-publish-url="{{ route('events.publish', $event) }}"
             data-public-url="{{ route('events.public', $event->slug) }}"
             @if ($event->isLocked())
                 data-redefine-confirm="This event has already taken place. If you changed its name, type or date, saving will use 1 event credit. Continue?"
             @endif>
            <div class="evt-section-body evt-actions-bar">
                <button type="button" class="btn-primary" data-save-all>
                    <i class="fa-solid fa-floppy-disk"></i> Save all changes
                </button>
                @if (! $event->is_published)
                    <button type="button" class="btn-primary" data-save-all data-publish>
                        <i class="fa-solid fa-bullhorn"></i> Save &amp; publish
                    </button>
                    <span class="evt-muted">Publishing saves everything first, then makes the invitation public.</span>
                @else
                    <span class="evt-muted">Saves your event details and invitation design together.</span>
                @endif
            </div>
        </div>

        @if (! $event->is_published)
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
                    <span class="evt-muted">Saves your event details, then makes the invitation public.</span>
                </div>
            </div>
        @else
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>Share</h2>
                    <p>Your live invitation link.</p>
                </div>
                <div class="evt-section-body">
                    <a href="{{ route('events.public', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug) }}</a>
                </div>
            </div>
        @endif

        <div class="profile-card profile-card--danger">
            <div class="profile-card-header">
                <div class="profile-card-icon" aria-hidden="true"><i class="fa-solid fa-trash"></i></div>
                <div>
                    <h3>Delete event</h3>
                    <p>This permanently removes the event and its cover image.</p>
                </div>
            </div>
            <div class="profile-form">
                <form method="post" action="{{ route('events.destroy', $event) }}" data-confirm="Delete this event permanently?">
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
