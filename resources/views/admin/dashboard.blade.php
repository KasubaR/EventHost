<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Admin dashboard</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Platform overview</h1>
                <p class="dph-sub">Operational snapshot across users, events, and messaging.</p>
            </div>
            @if(auth('admin')->user()?->can('analytics.view'))
                <a href="{{ route('admin.analytics') }}" class="btn-primary dash-header-cta">
                    <i class="fa-solid fa-chart-column" aria-hidden="true"></i> Analytics
                </a>
            @endif
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    @php
        $s = $stats;
    @endphp

    <div class="dash-stats dash-stats--six admin-stat-grid">
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--accent"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($s['users']) }}</div>
                <div class="dsc-label">Total users</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--cyan"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($s['events']) }}</div>
                <div class="dsc-label">Total events</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($s['published_events']) }}</div>
                <div class="dsc-label">Published events</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--purple"><i class="fa-solid fa-reply" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($s['rsvps']) }}</div>
                <div class="dsc-label">Total RSVPs</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--orange"><i class="fa-solid fa-flag" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($s['pending_reports']) }}</div>
                <div class="dsc-label">Pending reports</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--teal"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($s['failed_notifications']) }}</div>
                <div class="dsc-label">Failed notifications</div>
            </div>
        </div>
    </div>

    @if ($finance !== null)
        <section class="admin-finance">
            <div class="admin-section-head">
                <h2 class="admin-section-title">Finance</h2>
                <a href="{{ route('admin.payments.index') }}" class="admin-link">All payments</a>
            </div>
            <div class="dash-stats admin-stat-grid">
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($finance['revenue_total'], 2) }}</div>
                        <div class="dsc-label">Total revenue ({{ $currency }})</div>
                    </div>
                </div>
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--cyan"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($finance['revenue_month'], 2) }}</div>
                        <div class="dsc-label">Revenue this month ({{ $currency }})</div>
                    </div>
                </div>
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--accent"><i class="fa-solid fa-receipt" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($finance['completed_payments']) }}</div>
                        <div class="dsc-label">Completed payments</div>
                    </div>
                </div>
                <div class="dash-stat-card">
                    <div class="dsc-icon dsc-icon--orange"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></div>
                    <div class="dsc-body">
                        <div class="dsc-value">{{ number_format($finance['pending_payments']) }}</div>
                        <div class="dsc-label">Payments in progress</div>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <div class="admin-detail-grid admin-mt-lg">
        <section class="admin-panel-card">
            <h2>Recent users</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr><th>Name</th><th>Email</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse ($recentUsers as $u)
                        <tr>
                            <td>{{ $u->name }}</td>
                            <td>{{ $u->email }}</td>
                            <td>{{ $u->status }}</td>
                            <td>
                                @if(auth('admin')->user()?->can('users.view'))
                                    <a href="{{ route('admin.users.show', $u) }}" class="evt-btn-outline evt-btn-tiny">View</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="admin-muted">No users yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-panel-card">
            <h2>Recent failed notifications</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr><th>Channel</th><th>Type</th><th>Event</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($recentFailedNotifications as $log)
                        <tr>
                            <td>{{ $log->channel }}</td>
                            <td>{{ $log->type }}</td>
                            <td>{{ $log->event?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="admin-muted">No failures logged.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if(auth('admin')->user()?->can('notifications.view'))
                <p class="admin-mt-sm"><a href="{{ route('admin.notifications.index', ['status' => 'failed']) }}" class="admin-link">View all failed</a></p>
            @endif
        </section>
    </div>

    @if ($pendingTicketingRequests !== null)
        <section class="admin-panel-card admin-mt-md">
            <h2>Ticketing requests</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr><th>Event</th><th>Organizer</th><th>Types</th><th>Submitted</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse ($pendingTicketingRequests as $ev)
                        <tr>
                            <td>{{ $ev->name }}</td>
                            <td>{{ $ev->user?->email ?? '—' }}</td>
                            <td>{{ $ev->ticket_types_count }}</td>
                            <td>{{ $ev->ticketing_submitted_at?->format('j M Y H:i') ?? '—' }}</td>
                            <td><a href="{{ route('admin.ticketing.show', $ev) }}" class="evt-btn-outline evt-btn-tiny">Review</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="admin-muted">No pending ticketing requests.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="admin-mt-sm"><a href="{{ route('admin.ticketing.index') }}" class="admin-link">View all ticketing requests</a></p>
        </section>
    @endif
</x-admin-layout>
