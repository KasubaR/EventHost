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
            <p class="admin-muted admin-mt-sm">Status: {{ $u->status }} · Events: {{ $u->events_count }} · Credits: {{ $u->event_credits }} · Phone: {{ $u->phone ?? '—' }}</p>
            <p class="admin-muted">Registered {{ $u->created_at->format('M j, Y g:i a') }}</p>

            @if(auth('admin')->user()?->can('users.manage_status'))
                <div class="admin-mt-md">
                    <h3 style="font-size:14px;font-weight:600;margin-bottom:8px;">Event Credits</h3>
                    <p class="admin-muted" style="margin-bottom:10px;">Current balance: <strong>{{ $u->event_credits }}</strong></p>
                    <form method="post" action="{{ route('admin.users.add-credits', $u) }}" class="profile-form" style="display:flex;gap:8px;align-items:flex-end;">
                        @csrf
                        <div style="flex:1;">
                            <label for="credits-input" style="font-size:13px;">Add credits</label>
                            <input id="credits-input" type="number" name="credits" min="1" max="100" value="1" class="profile-input" style="margin-top:4px;">
                        </div>
                        <button type="submit" class="btn-primary" style="white-space:nowrap;">Add credits</button>
                    </form>
                </div>
            @endif

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
                        <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Delete user</button>
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
                <p class="admin-mt-sm"><a href="{{ route('admin.events.index', ['q' => $u->email]) }}" class="admin-link">View events for this owner</a></p>
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Credit history</h2>
            <p class="admin-muted admin-mt-sm">
                Every movement of this user's event credits, newest first. Balance is
                <strong>{{ $u->event_credits }}</strong>.
            </p>
            <div class="admin-table-wrap admin-mt-sm">
                <table class="admin-table">
                    <thead>
                    <tr><th>When</th><th>Reason</th><th>Change</th><th>Balance</th><th>For</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($creditHistory as $entry)
                        <tr>
                            <td>{{ $entry->created_at?->format('M j, Y H:i') ?? '—' }}</td>
                            <td>{{ $entry->reasonLabel() }}</td>
                            <td class="{{ $entry->delta < 0 ? 'admin-credit-spend' : 'admin-credit-grant' }}">
                                {{ $entry->delta > 0 ? '+' : '' }}{{ $entry->delta }}
                            </td>
                            <td>{{ $entry->balance_after }}</td>
                            <td class="admin-muted">
                                @if ($entry->event)
                                    {{ $entry->event->name }}
                                @elseif ($entry->payment)
                                    {{ $entry->payment->plan_key }}
                                @else
                                    {{ $entry->note ?? '—' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="admin-muted">No credit movements yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
