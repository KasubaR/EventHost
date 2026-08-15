<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/checkin-scanner.css') }}">
    @endpush

    <x-slot name="title">Check-in scanner — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Check-in scanner</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                {{-- Pre-filtered to arrivals only, so the host doesn't have to leave
                     the scanner mid-event to pull the list from the guest page. --}}
                <a href="{{ route('events.guests.export', ['event' => $event, 'checked_in' => 'yes']) }}" class="evt-btn-outline"><i class="fa-solid fa-file-csv"></i> Download attendee list</a>
                <a href="{{ route('events.tables.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-qrcode"></i> Tables</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'staff-link-created')
        <div class="evt-admin-flash">Scanner link created — share it with door staff.</div>
    @elseif (session('status') === 'staff-link-revoked')
        <div class="evt-admin-flash">Scanner link revoked.</div>
    @endif

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value" data-checkin-total>{{ $stats['total'] }}</div>
                <div class="evt-stat-label">Total guests</div>
            </div>
            <div class="evt-stat-card evt-stat-card--accent">
                <div class="evt-stat-value" data-checkin-arrived>{{ $stats['checked_in'] }}</div>
                <div class="evt-stat-label">Checked in</div>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-body">
                @include('events.checkin.partials.scanner-widget', [
                    'checkinBase' => url('/events/'.$event->id.'/checkin'),
                    'guestQrBase' => url('/events/'.$event->id.'/checkin'),
                    'lookupUrl' => route('events.checkin.lookup', $event),
                    'checkInOpen' => $event->isCheckInOpen(),
                    'checkInDateLabel' => $event->event_date?->timezone(config('app.timezone'))->format('j M Y'),
                ])
            </div>
        </div>

        @if (count($arrivals) > 0)
            @php $maxArrivals = max(array_column($arrivals, 'count')); @endphp
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>Arrivals over time</h2>
                    <p>Guests checked in per 15-minute window.</p>
                </div>
                <div class="evt-section-body">
                    <div class="ckin-trend">
                        @foreach ($arrivals as $bucket)
                            <div class="ckin-trend-bar-wrap">
                                <div class="ckin-trend-bar" style="height: {{ $maxArrivals > 0 ? max(6, round($bucket['count'] / $maxArrivals * 100)) : 6 }}%" title="{{ $bucket['count'] }} at {{ $bucket['label'] }}"></div>
                                <span class="ckin-trend-label">{{ $bucket['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Door staff links</h2>
                <p>Share a link with staff who don't have a dashboard login — no account needed, just this link.</p>
            </div>
            <div class="evt-section-body">
                <form method="post" action="{{ route('events.checkin.links.store', $event) }}" class="ckin-link-form">
                    @csrf
                    <label class="evt-sr-only" for="staff_link_label">Label</label>
                    <input id="staff_link_label" type="text" name="label" class="profile-input" placeholder="e.g. Front door (optional)" maxlength="100">
                    <button type="submit" class="evt-btn-outline">Create scanner link</button>
                </form>

                @if ($staffLinks->isEmpty())
                    <p class="evt-muted ckin-links-empty">No scanner links yet.</p>
                @else
                    <ul class="ckin-links-list">
                        @foreach ($staffLinks as $link)
                            <li class="ckin-links-row">
                                <div>
                                    <strong>{{ $link->label ?? 'Untitled link' }}</strong>
                                    <span class="evt-muted">
                                        @if ($link->last_used_at)
                                            · last used {{ $link->last_used_at->diffForHumans() }}
                                        @else
                                            · not used yet
                                        @endif
                                    </span>
                                </div>
                                <div class="evt-copy-row">
                                    <button type="button" class="evt-icon-btn" data-copy-text="{{ $link->scannerUrl() }}" data-copy-label="Copy link" title="Copy scanner link">Copy link</button>
                                    <form method="post" action="{{ route('events.checkin.links.destroy', ['event' => $event, 'link' => $link]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Revoke this scanner link? Anyone using it will lose access immediately.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Revoke</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="{{ asset('js/vendor/jsqr.min.js') }}" defer></script>
        <script src="{{ asset('js/checkin-scanner.js') }}" defer></script>
        <script src="{{ asset('js/tables-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
