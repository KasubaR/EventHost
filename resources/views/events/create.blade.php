<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        @if ($productKind)
            <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
            <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
        @endif
    @endpush
    @if ($productKind)
        @push('scripts')
            <script src="{{ asset('js/events-form.js') }}" defer></script>
            <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
            <script src="{{ asset('js/custom-select.js') }}" defer></script>
        @endpush
    @endif

    <x-slot name="title">Create event</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Create Event</h1>
                @if (! $productKind)
                    <p class="dph-sub">First, choose how people join this event. You cannot change this later.</p>
                @elseif ($productKind === \App\Enums\EventProductKind::Ticketed)
                    <p class="dph-sub">Add details and save as a draft — drafts are free. Ticket sales go live after EventHost review, with no event credit.</p>
                @else
                    <p class="dph-sub">Add details and save as a draft — drafts are free. Publishing uses 1 event credit.</p>
                @endif
            </div>
            <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to events</a>
        </div>
    </x-slot>

    @if (! $productKind)
        @include('events.partials.steps', ['current' => 1, 'choosingKind' => true])

        <div class="evt-kind-picker">
            <a href="{{ route('events.create', array_filter(['kind' => 'invitation', 'template' => $templateSlug])) }}" class="evt-kind-card">
                <span class="evt-kind-card-icon" aria-hidden="true"><i class="fa-solid fa-envelope-open-text"></i></span>
                <strong>Invitation / RSVP</strong>
                <span class="evt-kind-card-hint">Guests respond on a personal or public invite. Publishing uses 1 event credit.</span>
            </a>
            <a href="{{ route('events.create', ['kind' => 'ticketed']) }}" class="evt-kind-card">
                <span class="evt-kind-card-icon" aria-hidden="true"><i class="fa-solid fa-ticket"></i></span>
                <strong>Ticketed event</strong>
                <span class="evt-kind-card-hint">Sell tickets through EventHost checkout (Lenco). EventHost reviews sales before they go live — no event credit.</span>
            </a>
        </div>
    @else
        @include('events.partials.steps', [
            'current' => 2,
            'ticketed' => $productKind === \App\Enums\EventProductKind::Ticketed,
        ])

        <form method="post" action="{{ route('events.store') }}" enctype="multipart/form-data" class="profile-form">
            @csrf
            <input type="hidden" name="product_kind" value="{{ $productKind->value }}">
            @if (! empty($prefTemplateId) && $productKind === \App\Enums\EventProductKind::Invitation)
                <input type="hidden" name="preferred_invitation_template_id" value="{{ $prefTemplateId }}">
            @endif
            @include('events.partials.form-fields', [
                'event' => null,
                'selectedProductKind' => $productKind,
                'kindChangeUrl' => route('events.create'),
            ])

            <div class="evt-section-body evt-actions-bar">
                @if ($productKind === \App\Enums\EventProductKind::Ticketed)
                    {{-- "Save draft" belongs to step 4, the one place you actually
                         revisit and re-save — this submit just creates the row and
                         moves the wizard on, so it reads as a forward action instead
                         of a second "save draft". --}}
                    <button type="submit" class="btn-primary">
                        Continue to tickets <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <span class="evt-muted">Ticket sales go live after EventHost review — no event credit.</span>
                @else
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i> Save draft
                    </button>
                    <span class="evt-muted">Publishing is available after save and uses 1 event credit.</span>
                @endif
            </div>
        </form>
    @endif
</x-app-layout>
