<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/events-form.js') }}" defer></script>
        {{-- Reused only for its generic initMoreMenus() (the portalled ⋮ dropdown
             behind [data-evt-more]) — everything else in this file targets guest
             page markup that doesn't exist here and no-ops. See CLAUDE.md's
             "parallel, not shared" guidance: this loads the already-shipped file
             as-is rather than duplicating ~100 lines of toggle/portal logic. --}}
        <script src="{{ asset('js/guests-admin.js') }}" defer></script>
    @endpush

    <x-slot name="title">Tickets — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Tickets</h1>
                <p class="dph-sub">{{ $event->name }} · {{ number_format($tickets->total()) }} issued</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.tickets.export', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-download"></i> Export CSV</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'tickets'])

    @if (session('status') === 'ticket-resent')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Confirmation email resent.</div>
    @elseif (session('status') === 'ticket-reissued')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> New QR issued and emailed to the buyer. The old one no longer works.</div>
    @elseif (session('status') === 'ticket-cancelled')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket cancelled.</div>
    @elseif (session('status') === 'ticket-checked-in')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket checked in.</div>
    @elseif (session('status') === 'ticket-already-checked-in')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> This ticket was already checked in.</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="evt-stack">
        <div class="evt-section">
            <div class="evt-section-body evt-table-wrap">
                @if ($tickets->isEmpty())
                    <p class="evt-muted">No tickets have been issued yet.</p>
                @else
                    <table class="evt-table tkt-ticket-table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Type</th>
                                <th>Buyer</th>
                                <th>Status</th>
                                <th>Check-in</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tickets as $i => $ticketRow)
                                @php
                                    $buyer = $ticketRow->attendee_name ?: $ticketRow->order?->buyer_name;
                                    $checkInLabel = in_array($ticketRow->status, [\App\Enums\TicketStatus::Refunded, \App\Enums\TicketStatus::Cancelled], true)
                                        ? '—'
                                        : ($ticketRow->isCheckedIn() ? 'Yes' : 'No');
                                @endphp
                                <tr>
                                    <td>EH-{{ str_pad((string) ($startingOrdinal + $i), 3, '0', STR_PAD_LEFT) }}</td>
                                    <td>{{ $ticketRow->ticketType?->name ?? '—' }}</td>
                                    <td>{{ $buyer ?: '—' }}</td>
                                    <td><span class="evt-pill evt-pill--{{ $ticketRow->status->value }}">{{ $ticketRow->status->label() }}</span></td>
                                    <td>
                                        {{ $checkInLabel }}
                                        @if ($checkInLabel === 'Yes' && $ticketRow->checkedInByLabel())
                                            <span class="tkt-checkin-by">{{ $ticketRow->checkedInByLabel() }}</span>
                                        @endif
                                    </td>
                                    <td class="evt-table-actions">
                                        <div class="evt-more" data-evt-more>
                                            <button type="button" class="evt-icon-btn evt-more-toggle" data-evt-more-toggle hidden aria-expanded="false" aria-haspopup="true" aria-label="More actions for {{ $buyer ?: 'this ticket' }}">
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>
                                            <div class="evt-more-menu" data-evt-more-menu>
                                                <a href="{{ $ticketRow->publicUrl() }}" target="_blank" rel="noopener noreferrer" class="evt-more-item" role="menuitem">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i> <span>View</span>
                                                </a>

                                                <form method="post" action="{{ route('events.tickets.resend', [$event, $ticketRow]) }}" class="evt-inline-form">
                                                    @csrf
                                                    <button type="submit" class="evt-more-item" role="menuitem">
                                                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <span>Resend</span>
                                                    </button>
                                                </form>

                                                @if ($ticketRow->status === \App\Enums\TicketStatus::Valid)
                                                    @if ($event->ownerHasPremiumEventTools())
                                                        <form method="post" action="{{ route('events.tickets.confirm-checkin', [$event, $ticketRow]) }}" class="evt-inline-form">
                                                            @csrf
                                                            <button type="submit" class="evt-more-item" role="menuitem">
                                                                <i class="fa-solid fa-qrcode" aria-hidden="true"></i> <span>Check in</span>
                                                            </button>
                                                        </form>
                                                    @endif

                                                    <form method="post" action="{{ route('events.tickets.reissue', [$event, $ticketRow]) }}" class="evt-inline-form" data-confirm="Issue a new QR for this ticket? Any copy already shared stops working, and the buyer is emailed a replacement.">
                                                        @csrf
                                                        <button type="submit" class="evt-more-item" role="menuitem">
                                                            <i class="fa-solid fa-rotate" aria-hidden="true"></i> <span>Reissue QR</span>
                                                        </button>
                                                    </form>

                                                    <form method="post" action="{{ route('events.tickets.cancel', [$event, $ticketRow]) }}" class="evt-inline-form" data-confirm="Cancel this ticket? The buyer will no longer be able to use it.">
                                                        @csrf
                                                        <button type="submit" class="evt-more-item" role="menuitem">
                                                            <i class="fa-solid fa-ban" aria-hidden="true"></i> <span>Cancel</span>
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="tkt-pagination">
                        {{ $tickets->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
