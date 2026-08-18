<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @push('scripts')
        @vite(['resources/js/analytics-charts.js'])
    @endpush

    @php
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
                <p class="dph-sub">
                    <span class="evt-type-tag">{{ \App\Models\Event::TYPE_LABELS[$event->event_type] ?? $event->event_type }}</span>
                    @if ($event->isTicketed())
                        <span class="evt-type-tag">{{ $event->product_kind->label() }}</span>
                    @endif
                </p>
            </div>
            <div class="evt-card-actions">
                @if ($event->isTicketed())
                    {{-- While still setting up (Draft/Rejected), send them to the same
                         Tickets step the creation wizard uses rather than the empty
                         ticketing dashboard — see plans/ticketing.md wizard reorder. --}}
                    <a href="{{ route($event->canSubmitTicketing() ? 'events.ticket-types.index' : 'events.tickets.overview', $event) }}" class="btn-primary"><i class="fa-solid fa-ticket"></i> Tickets</a>
                @else
                    <a href="{{ route('events.guests.index', $event) }}" class="btn-primary"><i class="fa-solid fa-users"></i> Guests & RSVPs</a>
                @endif
                @if ($event->isTicketed())
                    <a href="{{ route('events.tickets.checkin.scan', $event) }}" class="evt-btn-outline">
                        <i class="fa-solid fa-qrcode"></i> Check-in scanner
                        @unless ($event->ownerHasPremiumEventTools())
                            <span class="evt-credit-badge">Pro</span>
                        @endunless
                    </a>
                @else
                    <a href="{{ route('events.tables.index', $event) }}" class="evt-btn-outline">
                        <i class="fa-solid fa-qrcode"></i> QR check-in & photo wall
                        @unless ($event->ownerHasPremiumEventTools())
                            <span class="evt-credit-badge">Pro</span>
                        @endunless
                    </a>
                @endif
                @if ($event->invitation_template_id !== null)
                    {{-- Host-only view (works for drafts and private events too), not the /e/{slug} public link.
                         from=show so its back link returns here instead of to the edit form. --}}
                    <a href="{{ route('events.preview', ['event' => $event, 'from' => 'show']) }}" target="_blank" rel="noopener" class="evt-btn-outline"><i class="fa-solid fa-eye"></i> Preview</a>
                @endif
                <a href="{{ route('events.edit', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
                <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-list"></i> All events</a>
            </div>
        </div>
    </x-slot>

    <div class="evt-stack">
        @unless ($event->is_public)
            <div class="evt-flash evt-flash--info">
                <i class="fa-solid fa-circle-info"></i>
                This is a private event — it has no public page. Share each guest's personal invite link from
                <a href="{{ route('events.guests.index', $event) }}">Guests</a>.
            </div>
        @endunless

        @if ($event->isTicketed())
            <div class="evt-grid-2 evt-rsvp-summary-grid">
                <div class="evt-stat-card">
                    <div class="evt-stat-value">{{ $event->ticketTypes()->count() }}</div>
                    <div class="evt-stat-label">Ticket types</div>
                </div>
                <div class="evt-stat-card">
                    <div class="evt-stat-value">{{ $event->ticketing_status->label() }}</div>
                    <div class="evt-stat-label">Ticket sales</div>
                </div>
                <div class="evt-stat-card">
                    <div class="evt-stat-value">{{ $event->commission_mode?->label() ?? '—' }}</div>
                    <div class="evt-stat-label">Commission</div>
                </div>
                <div class="evt-stat-card evt-stat-card--accent">
                    <div class="evt-stat-value">{{ $event->commissionPercent() }}%</div>
                    <div class="evt-stat-label">EventHost commission</div>
                </div>
            </div>
        @else
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
        @endif

        {{-- Cover image, date, venue, guest limit, description etc. used to be listed here too, but
             that duplicated what "Preview" now shows in full, real context — keep this section to the
             status message the preview page doesn't cover. The private-event note has its own tip at
             the top of the page instead, so there is nothing left to show here for that case. --}}
        @if ($event->isTicketed() && ! $event->ticketSalesAreApproved())
            <div class="evt-section">
                <div class="evt-section-body">
                    <p class="evt-muted">{{ $event->ticketing_status->label() }}. <a href="{{ route($event->canSubmitTicketing() ? 'events.ticket-types.index' : 'events.tickets.overview', $event) }}">Manage tickets</a> — sales go live after EventHost activates them.</p>
                </div>
            </div>
        @elseif (! $event->is_published)
            <div class="evt-section">
                <div class="evt-section-body">
                    <p class="evt-muted">This event is still a draft. Edit and publish to share it.</p>
                </div>
            </div>
        @elseif ($event->is_public)
            <div class="evt-section">
                <div class="evt-section-body">
                    <p class="evt-muted">Public page: <a href="{{ route('events.public', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug) }}</a></p>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
