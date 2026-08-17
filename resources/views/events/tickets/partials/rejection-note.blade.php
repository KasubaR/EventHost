{{-- Shared with events/edit.blade.php (wizard step 4). Expects $event. --}}
@if ($event->ticketing_status === \App\Enums\TicketingStatus::Rejected && $event->ticketing_rejection_note)
    <div class="evt-flash evt-flash--warn">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Activation was declined: {{ $event->ticketing_rejection_note }}
    </div>
@endif
