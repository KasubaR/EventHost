<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
    @endpush

    <x-slot name="title">Notifications</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Notification logs</h1>
                <p class="dph-sub">Sent / pending / failed delivery attempts.</p>
            </div>
        </div>
    </x-slot>

    <div class="dash-stats dash-stats--six admin-stat-grid admin-mt-md">
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['sent']) }}</div>
                <div class="dsc-label">Sent</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--orange"><i class="fa-solid fa-clock" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['pending']) }}</div>
                <div class="dsc-label">Pending</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--accent"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['failed']) }}</div>
                <div class="dsc-label">Failed</div>
            </div>
        </div>
    </div>

    <form method="get" action="{{ route('admin.notifications.index') }}" class="admin-filter-bar admin-mt-md">
        <div>
            <label class="evt-sr-only" for="admin-notif-status">Status</label>
            <select id="admin-notif-status" name="status">
                <option value="">Any status</option>
                <option value="sent" @selected($filterStatus === 'sent')>Sent</option>
                <option value="pending" @selected($filterStatus === 'pending')>Pending</option>
                <option value="failed" @selected($filterStatus === 'failed')>Failed</option>
            </select>
        </div>
        <div>
            <label class="evt-sr-only" for="admin-notif-channel">Channel</label>
            <input id="admin-notif-channel" type="search" name="channel" value="{{ $filterChannel }}" placeholder="Channel e.g. mail">
        </div>
        <button type="submit" class="btn-primary">Filter</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>When</th>
                <th>Channel</th>
                <th>Type</th>
                <th>Status</th>
                <th>Event</th>
                <th>Guest</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td>{{ $log->created_at->format('M j, Y g:i a') }}</td>
                    <td>{{ $log->channel }}</td>
                    <td>{{ $log->type }}</td>
                    <td>{{ $log->status }}</td>
                    <td>{{ $log->event?->name ?? '—' }}</td>
                    <td>{{ $log->guest?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="admin-muted">No log rows.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($logs->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $logs->links() }}</div>
    @endif
</x-admin-layout>
