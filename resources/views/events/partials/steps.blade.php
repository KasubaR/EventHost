@php
    // Ticketed events skip the "choose layout" step entirely — they have no
    // invitation template to pick (see events/tickets/landing.blade.php).
    $ticketed = $ticketed ?? false;
    $choosingKind = $choosingKind ?? false;
    $current = $current ?? 1;

    $labels = match (true) {
        $choosingKind => ['How People Join', 'Event Details'],
        $ticketed => ['How People Join', 'Event Details', 'Tickets', 'Organizer Details', 'Review Details & Request Activation'],
        default => ['How People Join', 'Event Details', 'Choose Layout', 'Customize & Publish'],
    };

    // Once the event exists, a finished step is a way back to it. The event is read from the including view's own
    // $event (the layout picker, ticket setup and edit pages all have one); the first two steps have no page of their
    // own after creation, so both go to the edit page, where the details can be changed and how people join is shown
    // (it is fixed at creation). Nothing links to the page you are already on.
    $stepEvent = ($event ?? null) instanceof \App\Models\Event && $event->exists ? $event : null;
    $stepUrl = function (int $n) use ($stepEvent, $ticketed, $current): ?string {
        if ($stepEvent === null || $n >= $current || $n > ($ticketed ? 4 : 3)) {
            return null;
        }

        // A ticketed event's edit page is the review step and is closed until a ticket type and the organizer details
        // exist; `details=1` opens it as the details form (the controller still refuses to submit for activation).
        $url = match (true) {
            $n <= 2 => route('events.edit', $ticketed ? [$stepEvent, 'details' => 1] : $stepEvent),
            $ticketed && $n === 4 => route('public-events.organizer.edit', $stepEvent),
            $ticketed => route('public-events.ticket-types.index', $stepEvent),
            default => route('events.choose-template', $stepEvent),
        };

        return strtok($url, '?') === url()->current() ? null : $url;
    };
@endphp

<nav class="evt-steps" aria-label="Event creation steps">
    <ol class="evt-steps-list">
        @foreach ($labels as $i => $label)
            @php
                $n = $i + 1;
                $state = $current === $n || (! $choosingKind && $ticketed && $n === 5 && $current >= 5)
                    ? 'evt-step--active'
                    : ($current > $n ? 'evt-step--done' : '');
                $url = $stepUrl($n);
            @endphp
            @if ($i > 0)
                <li class="evt-steps-connector" aria-hidden="true"></li>
            @endif
            <li class="evt-step {{ $state }}">
                @if ($url)
                    <a href="{{ $url }}" class="evt-step-link" title="Go back to this step">
                @endif
                <span class="evt-step-num" aria-hidden="true">
                    @if ($current > $n)
                        <i class="fa-solid fa-check"></i>
                    @else
                        {{ $n }}
                    @endif
                </span>
                <span class="evt-step-label">{{ $label }}</span>
                @if ($url)
                    </a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

@if (session('draft_reused'))
    <div class="profile-success evt-flash" role="status">
        <i class="fa-solid fa-circle-info"></i> You already created this event a moment ago, so we opened it instead of making a second one. Change anything below.
    </div>
@endif
