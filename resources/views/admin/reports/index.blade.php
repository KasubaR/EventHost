<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Reports</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Reports</h1>
                <p class="dph-sub">Moderation queue.</p>
            </div>
        </div>
    </x-slot>

    <form method="get" action="{{ route('admin.reports.index') }}" class="admin-filter-bar">
        <div>
            <label class="evt-sr-only" for="admin-report-status">Status</label>
            <select id="admin-report-status" name="status">
                <option value="">All statuses</option>
                <option value="pending" @selected($filterStatus === 'pending')>Pending</option>
                <option value="reviewed" @selected($filterStatus === 'reviewed')>Reviewed</option>
                <option value="resolved" @selected($filterStatus === 'resolved')>Resolved</option>
                <option value="dismissed" @selected($filterStatus === 'dismissed')>Dismissed</option>
            </select>
        </div>
        <button type="submit" class="btn-primary">Filter</button>
    </form>

    <div class="admin-table-wrap admin-mt-md">
        <table class="admin-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Status</th>
                <th>Reporter</th>
                <th>Event</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($reports as $report)
                <tr>
                    <td>#{{ $report->id }}</td>
                    <td>{{ $report->type }}</td>
                    <td>{{ $report->status }}</td>
                    <td>{{ $report->user?->email ?? '—' }}</td>
                    <td>{{ $report->event?->name ?? '—' }}</td>
                    <td>{{ $report->created_at->format('M j, Y') }}</td>
                    <td><a href="{{ route('admin.reports.show', $report) }}" class="evt-btn-outline evt-btn-tiny">Review</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="admin-muted">No reports.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($reports->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $reports->links() }}</div>
    @endif
</x-admin-layout>
