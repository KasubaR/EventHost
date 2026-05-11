<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/events-form.js') }}" defer></script>
        <script src="{{ asset('js/vendor/sortable.min.js') }}" defer></script>
        <script src="{{ asset('js/invitation-customize.js') }}" defer></script>
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

    <div class="evt-stack">
        <form method="post" action="{{ route('events.update', $event) }}" enctype="multipart/form-data" class="profile-form">
            @csrf
            @method('patch')
            @include('events.partials.form-fields', ['event' => $event])

            <div class="evt-section-body evt-actions-bar">
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

        @if (! $event->is_published)
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>Publish</h2>
                    <p>Make this invitation visible at its public link.</p>
                </div>
                <div class="evt-section-body evt-actions-bar">
                    <form method="post" action="{{ route('events.publish', $event) }}" class="evt-inline-form">
                        @csrf
                        @method('patch')
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-bullhorn"></i> Publish event
                        </button>
                    </form>
                    <span class="evt-muted">Public URL will be available after publishing.</span>
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
