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
            <a href="{{ route('events.create') }}" class="btn-primary">
                <i class="fa-solid fa-plus"></i> New event
            </a>
        </div>
    </x-slot>

    @if (session('status') === 'event-deleted')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Event deleted.</div>
    @endif

    @php
        $typeLabels = [
            'wedding' => 'Wedding',
            'birthday' => 'Birthday',
            'graduation' => 'Graduation',
            'corporate' => 'Corporate Event',
            'baby_shower' => 'Baby Shower',
            'funeral' => 'Memorial',
            'church' => 'Church Event',
        ];
    @endphp

    @if ($events->isEmpty())
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
        <div class="evt-list">
            @foreach ($events as $event)
                <article class="evt-card">
                    <div class="evt-card-main">
                        <img src="{{ $event->cover_image_url }}" alt="" class="evt-card-cover" width="96" height="54">
                        <div class="evt-card-body">
                            <h3>{{ $event->name }}</h3>
                            <p class="evt-card-meta">
                                <span class="evt-type-tag">{{ $typeLabels[$event->event_type] ?? $event->event_type }}</span>
                                · {{ $event->event_date->format('M j, Y') }}
                                @if ($event->event_time)
                                    · {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                                @endif
                            </p>
                            @if ($event->is_published)
                                <span class="evt-badge evt-badge--live"><i class="fa-solid fa-circle-check"></i> Published</span>
                            @else
                                <span class="evt-badge evt-badge--draft"><i class="fa-solid fa-pen"></i> Draft</span>
                            @endif
                        </div>
                    </div>
                    <div class="evt-card-actions">
                        <a href="{{ route('events.edit', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
                        <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-eye"></i> View</a>
                        @if ($event->is_published)
                            <a href="{{ route('events.public', $event->slug) }}" class="evt-btn-outline"><i class="fa-solid fa-link"></i> Public link</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        @if ($events->hasPages())
            <div class="evt-pagination">{{ $events->links() }}</div>
        @endif
    @endif
</x-app-layout>
