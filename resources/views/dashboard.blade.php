@php
    $a = $analytics;
    $t = $a['totals'];
@endphp

<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
        <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
    @endpush

    @push('scripts')
        @vite(['resources/js/analytics-charts.js'])
    @endpush

    <x-slot name="title">Dashboard</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Welcome back, {{ auth()->user()->name }}</h1>
                <p class="dph-sub">Here's what's happening with your events.</p>
            </div>
            <a href="{{ route('events.create') }}" class="btn-primary dash-header-cta">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> New Event
                <span class="billing-credit-pill">{{ auth()->user()->event_credits }} credit{{ auth()->user()->event_credits === 1 ? '' : 's' }}</span>
            </a>
        </div>
    </x-slot>

    <div class="dash-stats dash-stats--six">
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--accent"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ $t['events'] }}</div>
                <div class="dsc-label">Events</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--cyan"><i class="fa-solid fa-eye" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($t['invitation_views']) }}</div>
                <div class="dsc-label">Invitation views</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($t['guests']) }}</div>
                <div class="dsc-label">Guests invited</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--purple"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ $t['conversion_pct'] !== null ? $t['conversion_pct'].'%' : '—' }}</div>
                <div class="dsc-label">RSVP conversion</div>
                <div class="dsc-hint">{{ number_format($t['responded_guests']) }} / {{ number_format($t['guests']) }} responded</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--orange"><i class="fa-solid fa-clock" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($t['awaiting_guests']) }}</div>
                <div class="dsc-label">Awaiting RSVP</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--teal"><i class="fa-solid fa-chair" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($t['accepted_headcount']) }}</div>
                <div class="dsc-label">Accepted seats</div>
                <div class="dsc-hint">
                    @if ($t['attendance_pct'] !== null)
                        {{ $t['attendance_pct'] }}% vs guest list rows
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($a['has_events'])
        @php
            $dashAnalyticsChartPayload = [
                'daily_rsvps' => $a['daily_rsvps'],
                'status_chart' => $a['status_chart'],
                'group_breakdown' => $a['group_breakdown'],
            ];
        @endphp

        <script type="application/json" id="dash-analytics-json">@json($dashAnalyticsChartPayload)</script>

        <div data-analytics-root data-analytics-json-id="dash-analytics-json">
            <div class="dash-chart-grid">
                <section class="dash-panel" aria-labelledby="dash-daily-rsvps-title">
                    <div class="dash-panel-head">
                        <h2 id="dash-daily-rsvps-title" class="dash-panel-title">Daily RSVPs</h2>
                        <p class="dash-panel-sub">Last 14 days · all your events</p>
                    </div>
                    <div class="dash-analytics-chart-wrap" data-analytics-chart="daily">
                        <canvas role="img" aria-label="Daily RSVP submissions chart"></canvas>
                    </div>
                </section>

                <section class="dash-panel" aria-labelledby="dash-status-title">
                    <div class="dash-panel-head">
                        <h2 id="dash-status-title" class="dash-panel-title">RSVP breakdown</h2>
                        <p class="dash-panel-sub">Share of responses</p>
                    </div>
                    @if (count($a['status_chart']) > 0)
                        <ul class="dash-sr-only">
                            @foreach ($a['status_chart'] as $row)
                                <li>{{ $row['label'] }}, {{ $row['count'] }} responses ({{ $row['pct'] }}%).</li>
                            @endforeach
                        </ul>
                        <div class="dash-analytics-chart-wrap dash-analytics-chart-wrap--donut" data-analytics-chart="status">
                            <canvas role="img" aria-label="RSVP status breakdown chart"></canvas>
                        </div>
                    @else
                        <p class="dash-muted">No RSVP responses yet.</p>
                    @endif
                </section>
            </div>

            <div class="dash-chart-grid dash-chart-grid--single">
                <section class="dash-panel" aria-labelledby="dash-groups-title">
                    <div class="dash-panel-head">
                        <h2 id="dash-groups-title" class="dash-panel-title">Guest categories</h2>
                        <p class="dash-panel-sub">By group label · top segments</p>
                    </div>
                    @if (count($a['group_breakdown']) > 0)
                        <ul class="dash-sr-only">
                            @foreach ($a['group_breakdown'] as $row)
                                <li>{{ $row['label'] }}, {{ $row['count'] }} guests.</li>
                            @endforeach
                        </ul>
                        <div class="dash-analytics-chart-wrap dash-analytics-chart-wrap--groups" data-analytics-chart="groups">
                            <canvas role="img" aria-label="Guests by group chart"></canvas>
                        </div>
                    @else
                        <p class="dash-muted">Assign guests to groups to see category breakdown.</p>
                    @endif
                </section>

                <section class="dash-panel" aria-labelledby="dash-top-guests-title">
                    <div class="dash-panel-head">
                        <h2 id="dash-top-guests-title" class="dash-panel-title">Most active guests</h2>
                        <p class="dash-panel-sub">Highest accepted seat counts</p>
                    </div>
                    @if (count($a['top_guests']) > 0)
                        <ul class="dash-top-guests">
                            @foreach ($a['top_guests'] as $row)
                                <li class="dash-top-guest">
                                    <div class="dash-top-row">
                                        <span class="dash-top-name">{{ $row['name'] }}</span>
                                        <span class="dash-top-seats">{{ $row['attendee_count'] }} seats</span>
                                    </div>
                                    <div class="dash-top-event">{{ $row['event_name'] }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="dash-muted">No accepted RSVPs yet.</p>
                    @endif
                </section>
            </div>
        </div>
    @else
        <div class="dash-empty">
            <div class="dash-empty-icon"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i></div>
            <h2>No events yet</h2>
            <p>Create your first event invitation and start tracking RSVPs in real time.</p>
            <a href="{{ route('events.create') }}" class="btn-hero-primary dash-empty-cta">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Create Your First Event
            </a>
        </div>
    @endif

    @if ($staffing->isNotEmpty())
        <section class="dash-panel" aria-labelledby="dash-staffing-title">
            <div class="dash-panel-head">
                <h2 id="dash-staffing-title" class="dash-panel-title"><i class="fa-solid fa-user-shield"></i> Events you're staff on</h2>
                <p class="dash-panel-sub">Access someone else granted you</p>
            </div>
            <ul class="dash-top-guests">
                @foreach ($staffing as $staffEvent)
                    <li class="dash-top-guest">
                        <a href="{{ route('events.show', $staffEvent) }}" class="dash-top-row">
                            <span class="dash-top-name">{{ $staffEvent->name }}</span>
                            <span class="dash-top-seats">{{ $staffEvent->staffRoleFor(auth()->user())?->label() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</x-app-layout>
