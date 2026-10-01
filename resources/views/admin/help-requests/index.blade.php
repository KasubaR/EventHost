<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
    @endpush

    <x-slot name="title">Help requests</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Help requests</h1>
                <p class="dph-sub">Clients asking our team to set up or fix an event. You can only act on a client's account once you claim their request.</p>
            </div>
        </div>
    </x-slot>

    @if (session('error'))
        <div class="evt-flash evt-flash--warn" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> {{ session('error') }}</div>
    @endif

    <form method="get" action="{{ route('admin.help-requests.index') }}" class="admin-filter-bar">
        <div>
            <label class="evt-sr-only" for="hr-status">Status</label>
            <select id="hr-status" name="status" data-cs data-cs-size="sm" data-cs-search="never" data-cs-icon="fa-solid fa-filter">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filterStatus === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary">Filter</button>
    </form>

    <div class="admin-table-wrap admin-mt-md">
        <table class="admin-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Request</th>
                <th>Status</th>
                <th>Assigned</th>
                <th>Sent</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($requests as $helpRequest)
                <tr>
                    <td>#{{ $helpRequest->id }}</td>
                    <td>{{ $helpRequest->user?->name }}<br><span class="admin-muted">{{ $helpRequest->user?->email }}</span></td>
                    <td>{{ $helpRequest->kind->label() }}@if ($helpRequest->event)<br><span class="admin-muted">{{ $helpRequest->event->name }}</span>@endif</td>
                    <td>{{ $helpRequest->status->label() }}</td>
                    <td>{{ $helpRequest->assignedAdmin?->name ?? '-' }}</td>
                    <td>{{ $helpRequest->created_at->format('M j, Y') }}</td>
                    <td><a href="{{ route('admin.help-requests.show', $helpRequest) }}" class="evt-btn-outline evt-btn-tiny">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="admin-muted">No help requests.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($requests->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $requests->links() }}</div>
    @endif
</x-admin-layout>
