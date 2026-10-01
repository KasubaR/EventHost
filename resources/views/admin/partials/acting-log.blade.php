{{-- Audit trail of admins acting as a client: plans/admin-create-events.md Step 3.
     Expects $entries (AdminActivityLog collection with admin and event loaded). --}}
<div class="admin-panel-card">
    <h2>Team activity on the client's behalf</h2>
    <p class="admin-muted admin-mt-sm">
        What our team did while acting as the client, newest first. The client sees the same list under their help request.
    </p>
    <div class="admin-table-wrap admin-mt-sm">
        <table class="admin-table">
            <thead>
            <tr><th>When</th><th>Admin</th><th>Action</th><th>Event</th><th>Detail</th></tr>
            </thead>
            <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry->created_at?->format('M j, Y H:i') }}</td>
                    <td>{{ $entry->admin?->name ?? 'Deleted admin' }}</td>
                    <td>{{ $entry->label() }}</td>
                    <td class="admin-muted">{{ $entry->event?->name ?? '-' }}</td>
                    <td class="admin-muted">
                        @php $p = $entry->properties ?? []; @endphp
                        @if (isset($p['before'], $p['after']))
                            {{ $p['before'] }} &rarr; {{ $p['after'] }}
                        @elseif (isset($p['reason']))
                            {{ str_replace('_', ' ', $p['reason']) }}
                        @elseif (isset($p['name']))
                            {{ $p['name'] }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="admin-muted">Nothing yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
