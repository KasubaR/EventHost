<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">My events</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">My Events</h1>
                <p class="dph-sub">Drafts and published invitations for your invited guests.</p>
            </div>
            <a href="{{ route('events.create', ['audience' => 'private']) }}" class="btn-primary">
                <i class="fa-solid fa-plus"></i> New event
                <span class="evt-credit-badge">{{ auth()->user()->event_credits }} credit{{ auth()->user()->event_credits === 1 ? '' : 's' }}</span>
            </a>
        </div>
    </x-slot>

    @if (session('status') === 'event-deleted')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event deleted. You can restore it from Recently deleted below.</div>
    @elseif (session('status') === 'event-restored')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event restored.</div>
    @elseif (session('status') === 'no-event-credits')
        <div class="evt-flash evt-flash--warn"><i class="fa-solid fa-triangle-exclamation"></i> You have no event credits. <a href="{{ route('billing.show') }}">Buy an event credit</a> to publish.</div>
    @elseif (session('status') === 'draft-limit')
        <div class="evt-flash evt-flash--warn"><i class="fa-solid fa-triangle-exclamation"></i> You already have {{ \App\Models\Event::MAX_OPEN_DRAFTS }} unpublished drafts. Publish or delete one before creating another.</div>
    @endif

    {{-- Guests & RSVPs are managed per event — there's no single list across events, so the
         sidebar link lands here with a hint to pick one. Only shown when there's actually a
         choice to make; the empty-state branch below covers the zero-events case instead. --}}
    @if (request('from') === 'guests' && ($published->total() > 0 || $drafts->total() > 0))
        <div class="evt-flash evt-flash--info"><i class="fa-solid fa-circle-info"></i> Guests and RSVPs are managed per event. Pick an event below, then choose "Guests &amp; RSVPs" on it.</div>
    @endif

    @if ($published->total() === 0 && $drafts->total() === 0 && $deleted->total() === 0)
        @if (request('from') === 'guests')
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fa-solid fa-users"></i></div>
                <h2>No Events Yet</h2>
                <p>You need an event before you can manage guests and RSVPs. Create one to get started.</p>
                <a href="{{ route('events.create', ['audience' => 'private']) }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Create event</a>
            </div>
        @else
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
                <h2>No Events Yet</h2>
                <p>Create your first invitation to see it here.</p>
                <a href="{{ route('events.create', ['audience' => 'private']) }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Create event</a>
            </div>
        @endif
    @else
        @include('events.partials.my-events-groups', compact('published', 'drafts', 'deleted'))
    @endif
</x-app-layout>
