<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Music reports</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Music reports</h1>
                <p class="dph-sub">Copyright complaints about invitation music. Removing a track deletes the file and closes the report.</p>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    <div class="admin-section-head">
        <h2 class="admin-section-title">Open</h2>
        <span class="admin-muted">{{ $open->count() }}</span>
    </div>

    @forelse ($open as $report)
        <article class="admin-panel-card">
            <p>
                <strong>{{ $report->event_name ?: 'Unknown event' }}</strong>
                <span class="admin-muted">reported {{ $report->created_at->diffForHumans() }}</span>
            </p>
            <p class="admin-muted">
                From {{ $report->reporter_name }} (<a href="mailto:{{ $report->reporter_email }}" class="admin-link">{{ $report->reporter_email }}</a>)
                @if ($report->rights_holder) &middot; Rights holder: {{ $report->rights_holder }} @endif
            </p>
            <p>{{ $report->details }}</p>
            <p class="admin-muted">
                Track: <code>{{ $report->audio_path ?: 'none' }}</code>
                @if ($report->event)
                    &middot; <a href="{{ route('admin.events.show', $report->event) }}" class="admin-link">Open event</a>
                @endif
            </p>
            <div class="admin-actions admin-mt-sm">
                <form method="post" action="{{ route('admin.audio-reports.remove', $report) }}"
                      onsubmit="return confirm('Remove the music from this invitation and delete the file?');">
                    @csrf
                    <button type="submit" class="btn-primary"><i class="fa-solid fa-music"></i> Remove music</button>
                </form>
                <form method="post" action="{{ route('admin.audio-reports.dismiss', $report) }}">
                    @csrf
                    <button type="submit" class="evt-btn-outline">Dismiss</button>
                </form>
            </div>
        </article>
    @empty
        <div class="admin-panel-card"><p class="admin-muted">No open reports.</p></div>
    @endforelse

    <div class="admin-section-head admin-mt-lg">
        <h2 class="admin-section-title">Handled</h2>
        <span class="admin-muted">latest {{ $handled->count() }}</span>
    </div>

    @forelse ($handled as $report)
        <article class="admin-panel-card">
            <p>
                <strong>{{ $report->event_name ?: 'Unknown event' }}</strong>
                <span class="admin-muted">
                    {{ $report->status === 'removed' ? 'music removed' : 'dismissed' }}
                    {{ $report->handled_at?->diffForHumans() }}
                    @if ($report->handledBy) by {{ $report->handledBy->name }} @endif
                </span>
            </p>
            <p class="admin-muted">{{ $report->reporter_name }} &middot; {{ \Illuminate\Support\Str::limit($report->details, 160) }}</p>
        </article>
    @empty
        <div class="admin-panel-card"><p class="admin-muted">Nothing handled yet.</p></div>
    @endforelse
</x-admin-layout>
