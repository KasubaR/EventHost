{{-- Shared by events/index.blade.php (private) and events/public-index.blade.php
     (public) — plans/public-private-portals.md Phase 3. Identical published/
     draft/deleted layout either side; only the "nothing at all yet" empty
     state differs, and each caller handles that itself before including this. --}}
<section class="evt-group">
    <div class="evt-group-head">
        <h2 class="evt-group-title"><i class="fa-solid fa-circle-check"></i> Published</h2>
        <span class="evt-group-count">{{ $published->total() }}</span>
    </div>
    @if ($published->total() === 0)
        <p class="evt-group-empty">Nothing published yet. Finish a draft and publish it to share its link.</p>
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

@if ($deleted->total() > 0)
    <section class="evt-group">
        <div class="evt-group-head">
            <h2 class="evt-group-title"><i class="fa-solid fa-trash-can"></i> Recently deleted</h2>
            <span class="evt-group-count">{{ $deleted->total() }}</span>
        </div>
        @if (\App\Models\Event::retentionDays() > 0)
            <p class="evt-group-note">
                Deleted events can be restored for {{ \App\Models\Event::retentionDays() }} days, then they are removed permanently.
                Events with ticket sales or contribution payments are kept for our payment records.
            </p>
        @endif
        <div class="evt-list">
            @foreach ($deleted as $event)
                <article class="evt-card">
                    <div class="evt-card-main">
                        <img src="{{ $event->cover_image_url }}" alt="" class="evt-card-cover" width="96" height="54">
                        <div class="evt-card-body">
                            <h3>{{ $event->name }}</h3>
                            <p class="evt-card-meta">
                                Deleted {{ $event->deleted_at?->diffForHumans() }}
                                · <code>/e/{{ $event->slug }}</code>
                            </p>
                            @php $retention = \App\Support\EventRetentionNotice::for($event); @endphp
                            @if ($retention)
                                <p class="evt-card-retention evt-card-retention--{{ $retention->state }}">
                                    <i class="fa-solid {{ $retention->isKept() ? 'fa-lock' : 'fa-hourglass-half' }}" aria-hidden="true"></i>
                                    {{ $retention->label }}
                                </p>
                            @endif
                        </div>
                    </div>
                    <div class="evt-card-actions">
                        <form method="post" action="{{ route('events.restore', $event->id) }}">
                            @csrf
                            <button type="submit" class="evt-btn-outline"><i class="fa-solid fa-clock-rotate-left"></i> Restore</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
        @if ($deleted->hasPages())
            <div class="evt-pagination">{{ $deleted->links() }}</div>
        @endif
    </section>
@endif
