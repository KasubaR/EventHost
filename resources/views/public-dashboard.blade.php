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
                <p class="dph-sub">Ticketed and open-registration events — sales, check-ins and revenue.</p>
            </div>
            <a href="{{ route('events.create', ['audience' => 'public']) }}" class="btn-primary dash-header-cta">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> New Event
                <span class="billing-credit-pill">{{ auth()->user()->event_credits }} credit{{ auth()->user()->event_credits === 1 ? '' : 's' }}</span>
            </a>
        </div>
    </x-slot>

    @if ($pendingCustomQuote)
        <div class="dash-quote-banner" role="status">
            <div class="dash-quote-banner-body">
                <i class="fa-solid fa-gem" aria-hidden="true"></i>
                <div>
                    <strong>Your custom Enterprise quote is ready</strong>
                    <p>
                        Amount due: {{ $pendingCustomQuote->formattedAmount() }}
                        @if ($pendingCustomQuote->note)
                            — {{ $pendingCustomQuote->note }}
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
