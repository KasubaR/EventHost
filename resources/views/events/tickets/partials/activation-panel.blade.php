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
            <h2>Request Activation</h2>
            <p>EventHost reviews ticketed events before buyers can pay.</p>
        </div>
        <div class="evt-section-body evt-actions-bar">
            <button type="button" class="btn-primary" id="openActivationModal"
                    @disabled($ticketTypes->where('is_active', true)->isEmpty())>
                <i class="fa-solid fa-paper-plane"></i> Submit for activation
            </button>
            @if ($ticketTypes->where('is_active', true)->isEmpty())
                <span class="evt-muted">Add an active ticket type first.</span>
            @endif
        </div>
    </div>

    {{-- Custom confirm dialog, same vanilla-JS overlay pattern as the account
         delete modal (settings/partials/delete-account-form.blade.php) — just
         an --accent icon instead of the danger pink, since this confirms a
         normal forward action rather than something destructive. --}}
    <div class="profile-modal-overlay" id="activationModalOverlay" role="dialog" aria-modal="true" aria-labelledby="activationModalTitle">
        <div class="profile-modal">
            <div class="profile-modal-header">
                <div class="profile-modal-icon profile-modal-icon--accent"><i class="fa-solid fa-paper-plane"></i></div>
                <h3 id="activationModalTitle">Submit for EventHost review?</h3>
                <p>EventHost checks your ticket types and details before turning sales on. You can keep editing the event while this is pending.</p>
            </div>

            <form method="post" action="{{ route('events.ticketing.submit', $event) }}" class="profile-modal-form">
                @csrf
                <div class="profile-modal-actions">
                    <button type="button" class="profile-modal-cancel" id="closeActivationModal">Cancel</button>
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-paper-plane"></i> Yes, Submit for Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        const overlay  = document.getElementById('activationModalOverlay');
        const openBtn  = document.getElementById('openActivationModal');
        const closeBtn = document.getElementById('closeActivationModal');
        if (!overlay || !openBtn) return;

        function open()  { overlay.classList.add('is-open'); }
        function close() { overlay.classList.remove('is-open'); }

        openBtn.addEventListener('click', open);
        if (closeBtn) closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    }());
    </script>
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
