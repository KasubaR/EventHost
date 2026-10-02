@php
    $t = $analytics['totals'];
@endphp

<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
        <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
    @endpush

    <x-slot name="title">Public dashboard</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Public portal</h1>
                <p class="dph-sub">Ticketed and open-registration events: sales, check-ins and revenue.</p>
            </div>
            <a href="{{ route('events.create', ['audience' => 'public']) }}" class="btn-primary dash-header-cta">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> New Event
            </a>
        </div>
    </x-slot>

    @if ($migratedEvents->isNotEmpty())
        <div class="dash-notice-banner" role="status">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <div>
                <strong>{{ $migratedEvents->count() === 1 ? 'An event moved to this portal' : 'Some events moved to this portal' }}</strong>
                <p>{{ $migratedEvents->count() === 1 ? "It's" : "They're" }} here now because {{ $migratedEvents->count() === 1 ? 'it is' : 'they are' }} open to the public. Before the Public/Private split, every event lived on one shared "My Events" list.</p>
                <ul class="dash-notice-events">
                    @foreach ($migratedEvents as $migratedEvent)
                        <li class="dash-notice-event">
                            <span>{{ $migratedEvent->name }}</span>
                            <form method="post" action="{{ route('events.audience-migration-notice.dismiss', $migratedEvent) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="dash-notice-event-dismiss">Got it</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($pendingCustomQuote)
        <div class="dash-quote-banner" role="status">
            <div class="dash-quote-banner-body">
                <i class="fa-solid fa-gem" aria-hidden="true"></i>
                <div>
                    <strong>Your custom Enterprise quote is ready</strong>
                    <p>
                        Amount due: {{ $pendingCustomQuote->formattedAmount() }}
                        @if ($pendingCustomQuote->note)
                            · {{ $pendingCustomQuote->note }}
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('billing.show', ['plan' => 'enterprise']) }}" class="btn-primary">Pay on Billing</a>
        </div>
    @endif

    @if (! $analytics['has_events'])
        <div class="dash-empty">
            <div class="dash-empty-icon"><x-ticket-icon /></div>
            <h2>No public events yet</h2>
            <p>Create a ticketed event, or an invitation event open to everyone, and it lands here.</p>
            <a href="{{ route('events.create', ['audience' => 'public']) }}" class="btn-hero-primary dash-empty-cta">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Create Your First Public Event
            </a>
        </div>
    @else
        <div class="dash-stats dash-stats--six">
            <div class="dash-stat-card">
                <div class="dsc-icon dsc-icon--accent"><x-ticket-icon /></div>
                <div class="dsc-body">
                    <div class="dsc-value">{{ $t['events'] }}</div>
                    <div class="dsc-label">Public events</div>
                    <div class="dsc-hint">{{ $t['ticketed_events'] }} ticketed · {{ $t['open_registration_events'] }} free registration</div>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dsc-icon dsc-icon--cyan"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></div>
                <div class="dsc-body">
                    <div class="dsc-value">{{ $t['pending_review'] }}</div>
                    <div class="dsc-label">Awaiting EventHost review</div>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-receipt" aria-hidden="true"></i></div>
                <div class="dsc-body">
                    <div class="dsc-value">{{ number_format($t['tickets_sold']) }}</div>
                    <div class="dsc-label">Tickets sold</div>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dsc-icon dsc-icon--purple"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></div>
                <div class="dsc-body">
                    <div class="dsc-value">{{ number_format($t['checked_in']) }}</div>
                    <div class="dsc-label">Checked in</div>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dsc-icon dsc-icon--orange"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></div>
                <div class="dsc-body">
                    <div class="dsc-value">K{{ number_format($t['gross_amount'], 2) }}</div>
                    <div class="dsc-label">Gross sales</div>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dsc-icon dsc-icon--teal"><i class="fa-solid fa-wallet" aria-hidden="true"></i></div>
                <div class="dsc-body">
                    <div class="dsc-value">K{{ number_format($t['host_amount'], 2) }}</div>
                    <div class="dsc-label">Your revenue</div>
                    <div class="dsc-hint">Recorded payouts are on each event's own Revenue tab</div>
                </div>
            </div>
        </div>

        @if ($t['open_registration_events'] > 0)
            @php
                $reg = $analytics['registrations'];
                $regMax = max(1, collect($reg['daily'])->max('count'));
            @endphp

            <h2 class="dash-section-title">Free registration</h2>
            <div class="dash-stats dash-stats--four">
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-user-check" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($reg['registered']) }}</div>
                        <div class="dsc-label">Registrations</div>
                    </div>
                </div>
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--accent"><i class="fa-solid fa-people-group" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($reg['headcount']) }}</div>
                        <div class="dsc-label">Expected headcount</div>
                        <div class="dsc-hint">Includes plus-ones</div>
                    </div>
                </div>
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--cyan"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($reg['awaiting_approval']) }}</div>
                        <div class="dsc-label">Awaiting your approval</div>
                    </div>
                </div>
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--purple"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($reg['checked_in']) }}</div>
                        <div class="dsc-label">Checked in</div>
                    </div>
                </div>
            </div>

            <div class="dash-chart-grid">
                <section class="dash-panel" aria-labelledby="dash-reg-daily-title">
                    <div class="dash-panel-head">
                        <h2 id="dash-reg-daily-title" class="dash-panel-title">Daily registrations</h2>
                        <p class="dash-panel-sub">Last 14 days · free-registration events</p>
                    </div>
                    <div class="dash-mini-bars" role="img" aria-label="Registrations per day over the last 14 days">
                        @foreach ($reg['daily'] as $day)
                            <div class="dash-mini-bar" title="{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M') }}: {{ $day['count'] }}">
                                <span class="dash-mini-bar-fill" style="height: {{ $day['count'] > 0 ? max(6, round($day['count'] / $regMax * 100)) : 2 }}%"></span>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="dash-panel" aria-labelledby="dash-reg-events-title">
                    <div class="dash-panel-head">
                        <h2 id="dash-reg-events-title" class="dash-panel-title">Registrations by event</h2>
                        <p class="dash-panel-sub">Top 5</p>
                    </div>
                    <ul class="dash-top-guests">
                        @foreach ($reg['events'] as $row)
                            <li class="dash-top-guest">
                                <a href="{{ route('events.guests.index', $row['event']) }}" class="dash-top-row">
                                    <span class="dash-top-name">{{ $row['event']->name }}</span>
                                    <span class="dash-top-seats">{{ $row['registered'] }} registered · {{ $row['headcount'] }} expected</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        @endif

        @if ($analytics['upcoming']->isNotEmpty())
            <section class="dash-panel" aria-labelledby="dash-public-upcoming-title">
                <div class="dash-panel-head">
                    <h2 id="dash-public-upcoming-title" class="dash-panel-title">Upcoming</h2>
                    <p class="dash-panel-sub">Next 5 published public events</p>
                </div>
                <ul class="dash-top-guests">
                    @foreach ($analytics['upcoming'] as $upcomingEvent)
                        <li class="dash-top-guest">
                            <a href="{{ route('events.show', $upcomingEvent) }}" class="dash-top-row">
                                <span class="dash-top-name">{{ $upcomingEvent->name }}</span>
                                <span class="dash-top-seats">{{ $upcomingEvent->event_date->format('d M Y') }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
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
