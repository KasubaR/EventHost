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
            <div class="evt-card-badges">
                @if ($event->is_published)
                    <span class="evt-badge evt-badge--live"><i class="fa-solid fa-circle-check"></i> Published</span>
                @else
                    <span class="evt-badge evt-badge--draft"><i class="fa-solid fa-pen"></i> Draft</span>
                @endif
                {{-- Event::isLocked() applies to drafts too, so this sits alongside the status
                     badge rather than replacing it. --}}
                @if ($event->isLocked())
                    <span class="evt-badge evt-badge--done"><i class="fa-solid fa-flag-checkered"></i> Completed</span>
                @endif
            </div>
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
