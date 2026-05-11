<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Users</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Users</h1>
                <p class="dph-sub">Search and moderate accounts.</p>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <form method="get" action="{{ route('admin.users.index') }}" class="admin-filter-bar" role="search">
        <div>
            <label class="evt-sr-only" for="admin-user-q">Search</label>
            <input id="admin-user-q" type="search" name="q" value="{{ $search }}" placeholder="Name, email, phone">
        </div>
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Events</th>
                <th>Tier</th>
                <th>Status</th>
                <th>Registered</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->phone ?? '—' }}</td>
                    <td>{{ $user->events_count }}</td>
                    <td>{{ $user->subscription_tier instanceof \BackedEnum ? $user->subscription_tier->value : $user->subscription_tier }}</td>
                    <td>{{ $user->status }}</td>
                    <td>{{ $user->created_at->format('M j, Y') }}</td>
                    <td><a href="{{ route('admin.users.show', $user) }}" class="evt-btn-outline evt-btn-tiny">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="admin-muted">No users match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $users->links() }}</div>
    @endif
</x-admin-layout>
