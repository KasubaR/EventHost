<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Report #{{ $report->id }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.reports.index') }}">Reports</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>#{{ $report->id }}</span>
                </nav>
                <h1 class="dph-title">Report #{{ $report->id }}</h1>
                <p class="dph-sub">Status: {{ $report->status }}</p>
            </div>
            <a href="{{ route('admin.reports.index') }}" class="evt-btn-outline dash-header-cta">Back</a>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Details</h2>
            <p class="admin-muted admin-mt-sm"><strong>Type:</strong> {{ $report->type }}</p>
            <p class="admin-muted"><strong>Message:</strong></p>
            <p class="admin-mt-sm">{{ $report->message }}</p>
            <p class="admin-muted admin-mt-md"><strong>Reporter:</strong> {{ $report->user?->email ?? 'Anonymous' }}</p>
            <p class="admin-muted"><strong>Related event:</strong> {{ $report->event?->name ?? '—' }}</p>
            @if(auth('admin')->user()?->can('events.view'))
                @if ($report->event)
                    <p class="admin-mt-sm"><a href="{{ route('admin.events.show', $report->event) }}" class="admin-link">Open event</a></p>
                @endif
            @endif
        </div>

        @if(auth('admin')->user()?->can('reports.manage'))
            <div class="admin-panel-card">
                <h2>Resolution</h2>
                <form method="post" action="{{ route('admin.reports.update', $report) }}" class="profile-form admin-mt-sm">
                    @csrf
                    @method('PATCH')
                    <label for="report-status">Status</label>
                    <select id="report-status" name="status" class="profile-input admin-mt-sm">
                        <option value="pending" @selected($report->status === 'pending')>Pending</option>
                        <option value="reviewed" @selected($report->status === 'reviewed')>Reviewed</option>
                        <option value="resolved" @selected($report->status === 'resolved')>Resolved</option>
                        <option value="dismissed" @selected($report->status === 'dismissed')>Dismissed</option>
                    </select>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary">Save</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-admin-layout>
