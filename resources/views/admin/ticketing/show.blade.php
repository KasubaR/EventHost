<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
    @endpush

    @php
        $ev = $adminEvent;
    @endphp

    <x-slot name="title">Ticketing — {{ $ev->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.ticketing.index') }}">Ticketing</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>{{ $ev->name }}</span>
                </nav>
                <h1 class="dph-title">{{ $ev->name }}</h1>
                <p class="dph-sub">{{ $ev->ticketing_status->label() }} · {{ $ev->user?->email }}</p>
            </div>
            <a href="{{ route('admin.ticketing.index') }}" class="evt-btn-outline">Back to queue</a>
        </div>
    </x-slot>

    @if (session('status') === 'ticketing-approved')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket sales approved. The public page is live — checkout ships in a later phase.</div>
    @elseif (session('status') === 'ticketing-rejected')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Activation declined. The organizer can edit and resubmit.</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Summary</h2>
            <p class="admin-muted admin-mt-sm">{{ $ev->event_type_label }} · {{ $ev->event_date?->format('j M Y') }}</p>
            <p class="admin-muted">Commission mode: {{ $ev->commission_mode?->label() ?? '—' }}</p>
            <p class="admin-muted">Platform commission: {{ \App\Support\TicketingSettings::commissionPercent() }}%</p>
            <p class="admin-muted">Published: {{ $ev->is_published ? 'Yes' : 'No' }}</p>
            @if ($ev->ticketing_rejection_note)
                <p class="admin-muted">Last note: {{ $ev->ticketing_rejection_note }}</p>
            @endif

            @if ($ev->ticketing_status === \App\Enums\TicketingStatus::PendingReview && auth('admin')->user()?->can('ticketing.approve'))
                <form method="post" action="{{ route('admin.ticketing.approve', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    <label for="agreed_payout_on">Agreed payout date <span class="profile-optional">optional</span></label>
                    <input id="agreed_payout_on" name="agreed_payout_on" type="date" data-dtp
                           class="profile-input" value="{{ old('agreed_payout_on') }}"
                           min="{{ $ev->event_date?->format('Y-m-d') }}">
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary">Approve ticket sales</button>
                    </div>
                </form>

                <form method="post" action="{{ route('admin.ticketing.reject', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    <label for="ticketing_rejection_note">Decline reason</label>
                    <textarea id="ticketing_rejection_note" name="ticketing_rejection_note" class="profile-input" rows="3" required maxlength="2000">{{ old('ticketing_rejection_note') }}</textarea>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Decline</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Ticket types</h2>
            @forelse ($ev->ticketTypes as $type)
                <p class="admin-muted admin-mt-sm">
                    <strong>{{ $type->name }}</strong>
                    · {{ \App\Support\TicketingSettings::formatZmw($type->price) }}
                    · {{ $type->quantity === null ? 'Unlimited' : number_format($type->quantity) }}
                    · {{ $type->is_active ? 'Active' : 'Hidden' }}
                </p>
            @empty
                <p class="admin-muted admin-mt-sm">No ticket types.</p>
            @endforelse

            @if(auth('admin')->user()?->can('users.view') && $ev->user)
                <p class="admin-mt-md"><a href="{{ route('admin.users.show', $ev->user) }}">Organizer account</a></p>
            @endif
            @if(auth('admin')->user()?->can('events.view'))
                <p><a href="{{ route('admin.events.show', $ev) }}">Event record</a></p>
            @endif
        </div>
    </div>
</x-admin-layout>
