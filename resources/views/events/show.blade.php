<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @push('scripts')
        @vite(['resources/js/analytics-charts.js'])
    @endpush

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
        $eaTotals = $eventAnalytics['totals'];
        $evtAnalyticsChartPayload = [
            'daily_rsvps' => $eventAnalytics['daily_rsvps'],
            'status_chart' => $eventAnalytics['status_chart'],
            'group_breakdown' => $eventAnalytics['group_breakdown'],
        ];
    @endphp

    <x-slot name="title">{{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">{{ $event->name }}</h1>
                <p class="dph-sub"><span class="evt-type-tag">{{ $typeLabels[$event->event_type] ?? $event->event_type }}</span></p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.index', $event) }}" class="btn-primary"><i class="fa-solid fa-users"></i> Guests & RSVPs</a>
                <a href="{{ route('events.tables.index', $event) }}" class="evt-btn-outline">
                    <i class="fa-solid fa-qrcode"></i> QR check-in & photo wall
                    @unless ($event->ownerHasPremiumEventTools())
                        <span class="evt-credit-badge">Pro</span>
                    @endunless
                </a>
                <a href="{{ route('events.edit', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
                <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-list"></i> All events</a>
            </div>
        </div>
    </x-slot>

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $rsvpSummary['invited'] }}</div>
                <div class="evt-stat-label">Invited</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $rsvpSummary['pending'] }}</div>
                <div class="evt-stat-label">Pending</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $rsvpSummary['accepted'] }}</div>
                <div class="evt-stat-label">Accepted</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $rsvpSummary['declined'] }}</div>
                <div class="evt-stat-label">Declined</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $rsvpSummary['maybe'] }}</div>
                <div class="evt-stat-label">Maybe</div>
            </div>
            <div class="evt-stat-card evt-stat-card--accent">
                <div class="evt-stat-value">{{ $rsvpSummary['accepted_heads'] }}</div>
                <div class="evt-stat-label">Confirmed headcount</div>
            </div>
        </div>

        <script type="application/json" id="evt-analytics-json">@json($evtAnalyticsChartPayload)</script>

        <section class="evt-section evt-analytics-section" aria-labelledby="evt-analytics-title">
            <div class="evt-section-head">
                <h2 id="evt-analytics-title">Analytics</h2>
                <p>Invitation engagement and RSVP trends for this event.</p>
            </div>
            <div class="evt-section-body">
                <div class="evt-grid-2 evt-analytics-kpis">
                    <div class="evt-stat-card">
                        <div class="evt-stat-value">{{ number_format($eaTotals['invitation_views']) }}</div>
                        <div class="evt-stat-label">Invitation views</div>
                    </div>
                    <div class="evt-stat-card">
                        <div class="evt-stat-value">{{ $eaTotals['conversion_pct'] !== null ? $eaTotals['conversion_pct'].'%' : '—' }}</div>
                        <div class="evt-stat-label">RSVP conversion</div>
                        <div class="evt-analytics-kpi-hint">{{ number_format($eaTotals['responded_guests']) }} / {{ number_format($eaTotals['guests']) }} responded</div>
                    </div>
                    <div class="evt-stat-card">
                        <div class="evt-stat-value">{{ number_format($eaTotals['awaiting_guests']) }}</div>
                        <div class="evt-stat-label">Awaiting RSVP</div>
                    </div>
                    <div class="evt-stat-card evt-stat-card--accent">
                        <div class="evt-stat-value">{{ number_format($eaTotals['accepted_headcount']) }}</div>
                        <div class="evt-stat-label">Accepted seats</div>
                        <div class="evt-analytics-kpi-hint">
                            @if ($eaTotals['attendance_pct'] !== null)
                                {{ $eaTotals['attendance_pct'] }}% vs guest list rows
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>

                <div class="evt-analytics-root" data-analytics-root data-analytics-json-id="evt-analytics-json">
                    <div class="evt-analytics-chart-grid">
                        <div class="evt-analytics-panel">
                            <div class="evt-analytics-panel-head">
                                <h3 class="evt-analytics-panel-title">Daily RSVPs</h3>
                                <p class="evt-analytics-panel-sub">Last 14 days</p>
                            </div>
                            <div class="evt-analytics-chart-wrap" data-analytics-chart="daily">
                                <canvas aria-label="Daily RSVP submissions chart"></canvas>
                            </div>
                        </div>
                        <div class="evt-analytics-panel">
                            <div class="evt-analytics-panel-head">
                                <h3 class="evt-analytics-panel-title">RSVP mix</h3>
                                <p class="evt-analytics-panel-sub">Share of responses</p>
                            </div>
                            @if (count($eventAnalytics['status_chart']) > 0)
                                <ul class="evt-sr-only">
                                    @foreach ($eventAnalytics['status_chart'] as $row)
                                        <li>{{ $row['label'] }}, {{ $row['count'] }} responses ({{ $row['pct'] }}%).</li>
                                    @endforeach
                                </ul>
                                <div class="evt-analytics-chart-wrap evt-analytics-chart-wrap--donut" data-analytics-chart="status">
                                    <canvas aria-label="RSVP status breakdown chart"></canvas>
                                </div>
                            @else
                                <p class="evt-muted">No RSVP responses yet.</p>
                            @endif
                        </div>
                    </div>
                    @if (count($eventAnalytics['group_breakdown']) > 0)
                        <div class="evt-analytics-panel evt-analytics-panel--full">
                            <div class="evt-analytics-panel-head">
                                <h3 class="evt-analytics-panel-title">Guest categories</h3>
                                <p class="evt-analytics-panel-sub">By group label</p>
                            </div>
                            <ul class="evt-sr-only">
                                @foreach ($eventAnalytics['group_breakdown'] as $row)
                                    <li>{{ $row['label'] }}, {{ $row['count'] }} guests.</li>
                                @endforeach
                            </ul>
                            <div class="evt-analytics-chart-wrap evt-analytics-chart-wrap--groups" data-analytics-chart="groups">
                                <canvas aria-label="Guests by group chart"></canvas>
                            </div>
                        </div>
                    @endif
                </div>

                @if (count($eventAnalytics['top_guests']) > 0)
                    <div class="evt-analytics-top-guests">
                        <div class="evt-analytics-top-guests-head">
                            <h3 class="evt-analytics-panel-title">Highest accepted seat counts</h3>
                            <a href="{{ route('events.guests.index', ['event' => $event, 'response' => 'responded']) }}" class="evt-btn-outline evt-btn-tiny">View all responded</a>
                        </div>
                        <ul class="evt-analytics-top-list">
                            @foreach ($eventAnalytics['top_guests'] as $row)
                                <li class="evt-analytics-top-item">
                                    <div class="evt-analytics-top-row">
                                        <span class="evt-analytics-top-name">{{ $row['name'] }}</span>
                                        <span class="evt-analytics-top-seats">{{ $row['attendee_count'] }} seats</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="evt-analytics-top-guests">
                        <div class="evt-analytics-top-guests-head">
                            <h3 class="evt-analytics-panel-title">Responses</h3>
                            <a href="{{ route('events.guests.index', ['event' => $event, 'response' => 'responded']) }}" class="evt-btn-outline evt-btn-tiny">View all responded</a>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <div class="evt-section">
            <div class="evt-section-body">
                <img src="{{ $event->cover_image_url }}" alt="" class="evt-show-cover" width="1200" height="630">

                <ul class="evt-detail-list evt-detail-list--after-cover">
                    <li><i class="fa-regular fa-calendar"></i> {{ $event->event_date->format('l, F j, Y') }}
                        @if ($event->event_time)
                            · {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                        @endif
                    </li>
                    @if ($event->venue)
                        <li><i class="fa-solid fa-location-dot"></i> {{ $event->venue }}</li>
                    @endif
                    @if ($event->location_name)
                        <li><i class="fa-regular fa-map"></i> {{ $event->location_name }}</li>
                    @endif
                    @if ($event->guest_limit)
                        <li><i class="fa-solid fa-users"></i> Guest limit: {{ $event->guest_limit }}</li>
                    @endif
                </ul>

                @if ($event->description)
                    <div class="evt-desc-body">{{ $event->description }}</div>
                @endif

                @if ($event->is_published)
                    <p class="evt-muted">Public page: <a href="{{ route('events.public', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug) }}</a></p>
                @else
                    <p class="evt-muted">This event is still a draft. Edit and publish to share it.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
