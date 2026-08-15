<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">My events</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">My events</h1>
                <p class="dph-sub">Drafts and published invitations.</p>
            </div>
            @if (auth()->user()->canCreateEvent())
                <a href="{{ route('events.create') }}" class="btn-primary">
                    <i class="fa-solid fa-plus"></i> New event
                    <span class="evt-credit-badge">{{ auth()->user()->event_credits }} credit{{ auth()->user()->event_credits === 1 ? '' : 's' }}</span>
                </a>
            @else
                <a href="{{ route('billing.show') }}" class="btn-primary">
                    <i class="fa-solid fa-credit-card"></i> Buy event credit
                </a>
            @endif
        </div>
    </x-slot>

    @if (session('status') === 'event-deleted')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event deleted.</div>
    @elseif (session('status') === 'no-event-credits')
        <div class="evt-flash evt-flash--warn"><i class="fa-solid fa-triangle-exclamation"></i> You have no event credits. <a href="{{ route('billing.show') }}">Buy an event credit</a> to create a new event.</div>
    @endif

    @if ($published->total() === 0 && $drafts->total() === 0)
        @if (request('from') === 'guests')
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fa-solid fa-users"></i></div>
                <h2>No events yet</h2>
                <p>You need an event before you can manage guests and RSVPs. Create one to get started.</p>
                <a href="{{ route('events.create') }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Create event</a>
            </div>
        @else
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
                <h2>No events yet</h2>
                <p>Create your first invitation to see it here.</p>
                <a href="{{ route('events.create') }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Create event</a>
            </div>
        @endif
    @else
        <section class="evt-group">
            <div class="evt-group-head">
                <h2 class="evt-group-title"><i class="fa-solid fa-circle-check"></i> Published</h2>
                <span class="evt-group-count">{{ $published->total() }}</span>
            </div>
            @if ($published->total() === 0)
                <p class="evt-group-empty">Nothing published yet. Finish a draft and publish it to share its invitation link.</p>
            @else
                <div class="evt-list">
                    @foreach ($published as $event)
                        @include('events.partials.my-event-card', ['event' => $event])
                    @endforeach
                </div>
                @if ($published->hasPages())
                    <div class="evt-pagination">{{ $published->links() }}</div>
                @endif
            @endif
        </section>

        <section class="evt-group">
            <div class="evt-group-head">
                <h2 class="evt-group-title"><i class="fa-solid fa-pen"></i> Drafts</h2>
                <span class="evt-group-count">{{ $drafts->total() }}</span>
            </div>
            @if ($drafts->total() === 0)
                <p class="evt-group-empty">No drafts. Every event you have created is published.</p>
            @else
                <div class="evt-list">
                    @foreach ($drafts as $event)
                        @include('events.partials.my-event-card', ['event' => $event])
                    @endforeach
                </div>
                @if ($drafts->hasPages())
                    <div class="evt-pagination">{{ $drafts->links() }}</div>
                @endif
            @endif
        </section>
    @endif
</x-app-layout>
