<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Importing guests | {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Import guests</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Guests</a>
            </div>
        </div>
    </x-slot>

    @php
        $running = ! $import->isFinished() && ! $import->isStale();
    @endphp

    <div class="evt-stack">
        <div class="evt-section">
            <div class="evt-section-head">
                <h2><bdi>{{ $import->original_name }}</bdi></h2>
                <p>{{ number_format($import->total_rows) }} {{ Str::plural('row', $import->total_rows) }} under the heading row.</p>
            </div>
            <div class="evt-section-body profile-card-like">
                @if ($running)
                    <div class="evt-import-status"
                         data-import-progress-url="{{ route('events.guests.import.progress', ['event' => $event, 'guestImport' => $import]) }}">
                        <p class="evt-import-status-line">
                            @if ($import->status === \App\Models\GuestImport::STATUS_QUEUED)
                                <span data-import-progress-text>Waiting to start.</span>
                            @else
                                <span data-import-progress-text>Checked {{ number_format($import->processed_rows) }} of {{ number_format($import->total_rows) }} rows.</span>
                            @endif
                        </p>
                        <progress class="evt-import-progress" max="{{ max(1, $import->total_rows) }}" value="{{ $import->processed_rows }}" data-import-progress-bar>
                            {{ number_format($import->processed_rows) }} of {{ number_format($import->total_rows) }}
                        </progress>
                        <p class="evt-muted">Guests appear on the list as each batch is added. You can leave this page; the import keeps going and the guest list shows the result when it is done.</p>
                        <p><a href="{{ route('events.guests.import.show', ['event' => $event, 'guestImport' => $import]) }}" class="evt-btn-outline">Refresh</a></p>
                    </div>
                @else
                    <div class="evt-admin-flash">
                        @if ($import->isStale())
                            {{ \App\Models\GuestImport::STALE_MESSAGE }}
                        @elseif ($import->status === \App\Models\GuestImport::STATUS_FAILED)
                            {{ $import->failure_message }}
                        @else
                            Import finished.
                        @endif
                        @include('events.guests.partials.import-summary', ['summary' => $import->summary()])
                    </div>
                    <p class="evt-import-status-actions">
                        <a href="{{ route('events.guests.index', $event) }}" class="btn-primary">See the guest list</a>
                        <a href="{{ route('events.guests.import.create', $event) }}" class="evt-btn-outline">Import another file</a>
                    </p>
                @endif
            </div>
        </div>
    </div>

    @if ($running)
        @push('scripts')
            <script src="{{ asset('js/guest-import-status.js') }}" defer></script>
        @endpush
    @endif
</x-app-layout>
