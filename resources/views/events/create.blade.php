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
                @if (! $audience)
                    <p class="dph-sub">First, who is this event for? You cannot change this later.</p>
                @elseif ($audience === \App\Enums\EventAudience::Public && ! $productKind)
                    <p class="dph-sub">Next, how do people get in?</p>
                @elseif ($productKind === \App\Enums\EventProductKind::Ticketed)
                    <p class="dph-sub">Add details and save as a draft. Drafts are free. Ticket sales go live after EventHost review, with no event credit.</p>
                @elseif ($audience === \App\Enums\EventAudience::Public)
                    <p class="dph-sub">Add details and save as a draft. Drafts are free. EventHost reviews and quotes a price before it goes live, with no event credit.</p>
                @else
                    <p class="dph-sub">Add your event details, then choose to save as a draft or publish. Publishing uses 1 event credit.</p>
                @endif
            </div>
            <a href="{{ route($audience === \App\Enums\EventAudience::Public ? 'public-events.index' : 'events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to events</a>
        </div>
    </x-slot>

    @if (! $audience)
        @include('events.partials.steps', ['current' => 1, 'choosingKind' => true])

        <div class="evt-kind-picker">
            <a href="{{ route('events.create', array_filter(['audience' => 'private', 'template' => $templateSlug])) }}" class="evt-kind-card">
                <span class="evt-kind-card-icon" aria-hidden="true"><i class="fa-solid fa-envelope-open-text"></i></span>
                <strong>Private event</strong>
                <span class="evt-kind-card-hint">Wedding, birthday, graduation, baby shower, church event, corporate. For a specific invited group, invite-only.</span>
            </a>
            <a href="{{ route('events.create', ['audience' => 'public']) }}" class="evt-kind-card">
                <span class="evt-kind-card-icon" aria-hidden="true"><i class="fa-solid fa-earth-africa"></i></span>
                <strong>Public event</strong>
                <span class="evt-kind-card-hint">Concert, conference, festival, workshop, fundraiser. For a broad audience who discover, register or buy tickets.</span>
            </a>
        </div>
    @elseif ($audience === \App\Enums\EventAudience::Public && ! $productKind)
        @include('events.partials.steps', ['current' => 1, 'choosingKind' => true])

        <div class="evt-kind-picker">
            <a href="{{ route('events.create', ['audience' => 'public', 'kind' => 'ticketed']) }}" class="evt-kind-card">
                <span class="evt-kind-card-icon" aria-hidden="true"><x-ticket-icon /></span>
                <strong>Ticketed event</strong>
                <span class="evt-kind-card-hint">Sell tickets through EventHost checkout (Lenco). EventHost reviews sales before they go live. No event credit.</span>
            </a>
            @if (auth()->user()->canMakeEventsPublic())
                <a href="{{ route('events.create', ['audience' => 'public', 'kind' => 'invitation']) }}" class="evt-kind-card">
                    <span class="evt-kind-card-icon" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
                    <strong>Free registration</strong>
                    <span class="evt-kind-card-hint">Anyone can register. Open RSVP, listed on Discover. EventHost reviews and quotes a price before it goes live. No event credit.</span>
                </a>
            @else
                <div class="evt-kind-card evt-kind-card--locked">
                    <span class="evt-kind-card-icon" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
                    <strong>Free registration <span class="evt-credit-badge">Base</span></strong>
                    <span class="evt-kind-card-hint">Open RSVP, listed on Discover, no payment. Requires the Base plan or higher. <a href="{{ \App\Support\BillingPlan::checkoutUrlForTier(\App\Enums\SubscriptionTier::Base) }}">Upgrade to unlock it</a>.</span>
                </div>
            @endif
        </div>
    @else
        @include('events.partials.steps', [
            'current' => 2,
            'ticketed' => $productKind === \App\Enums\EventProductKind::Ticketed,
        ])

        <form method="post" action="{{ route('events.store') }}" enctype="multipart/form-data" class="profile-form">
            @csrf
            <input type="hidden" name="audience" value="{{ $audience->value }}">
            <input type="hidden" name="product_kind" value="{{ $productKind->value }}">
            @if (! empty($prefTemplateId) && $productKind === \App\Enums\EventProductKind::Invitation)
                <input type="hidden" name="preferred_invitation_template_id" value="{{ $prefTemplateId }}">
            @endif
            @include('events.partials.form-fields', [
                'event' => null,
                'audience' => $audience,
                'selectedProductKind' => $productKind,
                'kindChangeUrl' => $audience === \App\Enums\EventAudience::Public
                    ? route('events.create', ['audience' => 'public'])
                    : route('events.create'),
            ])

            <div class="evt-section-body evt-actions-bar">
                <a href="{{ $audience === \App\Enums\EventAudience::Public ? route('events.create', ['audience' => 'public']) : route('events.create') }}" class="evt-btn-outline">
                    <i class="fa-solid fa-arrow-left"></i> Back
                </a>
                {{-- Neither "Save draft" nor "Publish" belongs here — both are chosen
                     later, on step 4, once there's a design to save or publish. Every
                     path here just creates the row and moves the wizard on, so it
                     reads as a forward action instead. --}}
                @if ($productKind === \App\Enums\EventProductKind::Ticketed)
                    <button type="submit" class="btn-primary">
                        Continue to tickets <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <span class="evt-muted">Ticket sales go live after EventHost review. No event credit.</span>
                @elseif ($audience === \App\Enums\EventAudience::Public)
                    <button type="submit" class="btn-primary">
                        Next <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <span class="evt-muted">EventHost reviews and quotes a price after save. No event credit.</span>
                @else
                    <button type="submit" class="btn-primary">
                        Next <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <span class="evt-muted">Next you'll pick a layout and design your invitation, then choose to save a draft or publish.</span>
                @endif
            </div>
        </form>
    @endif
</x-app-layout>
