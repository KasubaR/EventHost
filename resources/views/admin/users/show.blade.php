<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @php
        $u = $adminUser;
    @endphp

    <x-slot name="title">{{ $u->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.users.index') }}">Users</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>{{ $u->name }}</span>
                </nav>
                <h1 class="dph-title">{{ $u->name }}</h1>
                <p class="dph-sub">{{ $u->email }}</p>
            </div>
            <a href="{{ route('admin.users.index') }}" class="evt-btn-outline dash-header-cta">Back to list</a>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Account</h2>
            <p class="admin-muted admin-mt-sm">Status: {{ $u->status }} · Events: {{ $u->events_count }} · Phone: {{ $u->phone ?? '—' }}</p>
            <p class="admin-muted">Registered {{ $u->created_at->format('M j, Y g:i a') }}</p>

            @if(auth('admin')->user()?->can('users.manage_status'))
                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.status', $u) }}" class="profile-form admin-mt-md">
                        @csrf
                        @method('PATCH')
                        <label for="user-status">Moderation status</label>
                        <select id="user-status" name="status" class="profile-input">
                            <option value="active" @selected($u->status === 'active')>Active</option>
                            <option value="suspended" @selected($u->status === 'suspended')>Suspended</option>
                        </select>
                        <div class="admin-actions admin-mt-md">
                            <button type="submit" class="btn-primary">Update status</button>
                        </div>
                    </form>
                @else
                    <p class="admin-muted admin-mt-md">You cannot change your own status here.</p>
                @endif
            @endif

            @if(auth('admin')->user()?->can('users.password_reset'))
                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.password-reset', $u) }}" class="profile-form admin-mt-md">
                        @csrf
                        <button type="submit" class="evt-btn-outline">Send password reset email</button>
                    </form>
                @endif
            @endif

            @if(auth('admin')->user()?->can('users.delete'))
                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.destroy', $u) }}" class="profile-form admin-mt-md" data-confirm="Delete this user and all owned data?">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="evt-btn-danger-outline">Delete user</button>
                    </form>
                @endif
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Recent events</h2>
            <div class="admin-table-wrap admin-mt-sm">
                <table class="admin-table">
                    <thead>
                    <tr><th>Name</th><th>Type</th><th>Published</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($u->events as $event)
                        <tr>
                            <td>{{ $event->name }}</td>
                            <td>{{ $event->event_type }}</td>
                            <td>{{ $event->is_published ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="admin-muted">No events.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if(auth('admin')->user()?->can('events.view'))
                <p class="admin-mt-sm"><a href="{{ route('admin.events.index', ['q' => $u->email]) }}">View events for this owner</a></p>
            @endif
        </div>
    </div>
</x-admin-layout>
