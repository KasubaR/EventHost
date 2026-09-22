<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">My public events</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">My Public Events</h1>
                <p class="dph-sub">Ticketed events and open-registration invitations.</p>
            </div>
            <a href="{{ route('events.create', ['audience' => 'public']) }}" class="btn-primary">
                <i class="fa-solid fa-plus"></i> New event
                <span class="evt-credit-badge">{{ auth()->user()->event_credits }} credit{{ auth()->user()->event_credits === 1 ? '' : 's' }}</span>
            </a>
        </div>
    </x-slot>

    @if (session('status') === 'event-deleted')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event deleted. You can restore it from Recently deleted below.</div>
    @elseif (session('status') === 'event-restored')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event restored.</div>
    @elseif (session('status') === 'ticketing-submitted')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Submitted for EventHost review. Ticket sales stay off until we approve.</div>
    @elseif (session('status') === 'draft-limit')
        <div class="evt-flash evt-flash--warn"><i class="fa-solid fa-triangle-exclamation"></i> You already have {{ \App\Models\Event::MAX_OPEN_DRAFTS }} unpublished drafts. Publish or delete one before creating another.</div>
    @endif

    @if ($published->total() === 0 && $drafts->total() === 0 && $deleted->total() === 0)
        <div class="dash-empty">
            <div class="dash-empty-icon"><x-ticket-icon /></div>
            <h2>No Public Events Yet</h2>
            <p>Create a ticketed event, or an invitation event open to everyone, to see it here.</p>
            <a href="{{ route('events.create', ['audience' => 'public']) }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Create event</a>
        </div>
    @else
        @include('events.partials.my-events-groups', compact('published', 'drafts', 'deleted'))
    @endif

    @if ($staffing->total() > 0)
        <section class="evt-group">
            <div class="evt-group-head">
                <h2 class="evt-group-title"><i class="fa-solid fa-user-shield"></i> Events you're staff on</h2>
                <span class="evt-group-count">{{ $staffing->total() }}</span>
            </div>
            <div class="evt-list">
                @foreach ($staffing as $event)
                    @include('events.partials.my-event-card', ['event' => $event])
                @endforeach
            </div>
            @if ($staffing->hasPages())
                <div class="evt-pagination">{{ $staffing->links() }}</div>
            @endif
        </section>
    @endif
</x-app-layout>
