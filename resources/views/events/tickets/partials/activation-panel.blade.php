{{--
    Shared "where things stand with ticket sales" panel — whichever one of
    Request activation / Pending review / Approved applies. Included from
    events/tickets/index.blade.php (the Tickets step / Settings page) and
    events/edit.blade.php (wizard step 4's closing action), so the
    submit-for-activation form and its disabled-state logic live in one place
    but can render as the true final step of both the wizard and ongoing
    Settings. Expects $event and $ticketTypes (a Collection of TicketType).
--}}
@if ($event->canSubmitTicketing())
    <div class="evt-section">
        <div class="evt-section-head">
            <h2>Request activation</h2>
            <p>EventHost reviews ticketed events before buyers can pay.</p>
        </div>
        <div class="evt-section-body evt-actions-bar">
            <form method="post" action="{{ route('events.ticketing.submit', $event) }}" data-confirm="Submit this event for EventHost to activate ticket sales?">
                @csrf
                <button type="submit" class="btn-primary" @disabled($ticketTypes->where('is_active', true)->isEmpty())>
                    <i class="fa-solid fa-paper-plane"></i> Submit for activation
                </button>
            </form>
            @if ($ticketTypes->where('is_active', true)->isEmpty())
                <span class="evt-muted">Add an active ticket type first.</span>
            @endif
        </div>
    </div>
@elseif ($event->ticketing_status === \App\Enums\TicketingStatus::PendingReview)
    <div class="evt-section">
        <div class="evt-section-body">
            <p class="evt-muted">Submitted {{ $event->ticketing_submitted_at?->format('j M Y, H:i') }}. Ticket sales stay off until EventHost approves.</p>
        </div>
    </div>
@elseif ($event->ticketSalesAreApproved())
    <div class="evt-section">
        <div class="evt-section-body">
            <p class="evt-muted">Public ticket page: <a href="{{ route('events.public', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug) }}</a></p>
            <p class="evt-muted">Checkout is not live yet — buyers cannot pay until the next ticketing phase ships.</p>
        </div>
    </div>
@endif
