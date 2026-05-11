<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
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
                                    <a href="{{ route('admin.users.show', $u) }}">View</a>
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
                <p class="admin-mt-sm"><a href="{{ route('admin.notifications.index', ['status' => 'failed']) }}">View all failed</a></p>
            @endif
        </section>
    </div>

    <div class="admin-detail-grid admin-mt-md">
        <section class="admin-panel-card">
            <h2>Recent events</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr><th>Event</th><th>Owner</th><th>Published</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($recentEvents as $ev)
                        <tr>
                            <td>{{ $ev->name }}</td>
                            <td>{{ $ev->user?->email ?? '—' }}</td>
                            <td>{{ $ev->is_published ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="admin-muted">No events.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-panel-card">
            <h2>Recent RSVPs</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr><th>Guest</th><th>Event</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($recentRsvps as $r)
                        <tr>
                            <td>{{ $r->guest?->name ?? '—' }}</td>
                            <td>{{ $r->event?->name ?? '—' }}</td>
                            <td>{{ $r->status instanceof \BackedEnum ? $r->status->value : $r->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="admin-muted">No RSVPs.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-admin-layout>
