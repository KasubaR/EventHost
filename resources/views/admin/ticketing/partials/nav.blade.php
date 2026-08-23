{{--
    Two-tab strip for the admin "Ticketing" area (Phase 23) — Approvals (the
    existing activation queue) and Revenue (money). Reuses the exact
    .admin-filter-row + evt-btn-outline pattern admin/ticketing/index.blade.php
    already uses for its status filter, so this reads as part of the same
    page family rather than importing the host-side .tkt-tabs component.
--}}
<div class="admin-panel-card">
    <nav class="admin-filter-row" aria-label="Ticketing section">
        <a href="{{ route('admin.ticketing.index') }}" class="evt-btn-outline {{ $active === 'approvals' ? 'is-active' : '' }}">
            <i class="fa-solid fa-clipboard-check"></i> Approvals
        </a>
        <a href="{{ route('admin.ticketing.revenue.index') }}" class="evt-btn-outline {{ $active === 'revenue' ? 'is-active' : '' }}">
            <i class="fa-solid fa-sack-dollar"></i> Revenue
        </a>
    </nav>
</div>
