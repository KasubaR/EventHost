@php
    // Ticketed events skip the "choose layout" step entirely — they have no
    // invitation template to pick (see events/tickets/landing.blade.php).
    $ticketed = $ticketed ?? false;
    $choosingKind = $choosingKind ?? false;
@endphp

@if ($choosingKind)
    <nav class="evt-steps" aria-label="Event creation steps">
        <ol class="evt-steps-list">
            <li class="evt-step evt-step--active">
                <span class="evt-step-num" aria-hidden="true">1</span>
                <span class="evt-step-label">How people join</span>
            </li>
            <li class="evt-steps-connector" aria-hidden="true"></li>
            <li class="evt-step">
                <span class="evt-step-num" aria-hidden="true">2</span>
                <span class="evt-step-label">Event details</span>
            </li>
        </ol>
    </nav>
@elseif ($ticketed)
    <nav class="evt-steps" aria-label="Event creation steps">
        <ol class="evt-steps-list">
            <li class="evt-step {{ $current === 1 ? 'evt-step--active' : ($current > 1 ? 'evt-step--done' : '') }}">
                <span class="evt-step-num" aria-hidden="true">
                    @if ($current > 1)
                        <i class="fa-solid fa-check"></i>
                    @else
                        1
                    @endif
                </span>
                <span class="evt-step-label">How people join</span>
            </li>
            <li class="evt-steps-connector" aria-hidden="true"></li>
            <li class="evt-step {{ $current === 2 ? 'evt-step--active' : ($current > 2 ? 'evt-step--done' : '') }}">
                <span class="evt-step-num" aria-hidden="true">
                    @if ($current > 2)
                        <i class="fa-solid fa-check"></i>
                    @else
                        2
                    @endif
                </span>
                <span class="evt-step-label">Event details</span>
            </li>
            <li class="evt-steps-connector" aria-hidden="true"></li>
            <li class="evt-step {{ $current >= 3 ? 'evt-step--active' : '' }}">
                <span class="evt-step-num" aria-hidden="true">3</span>
                <span class="evt-step-label">Tickets &amp; publish</span>
            </li>
        </ol>
    </nav>
@else
    <nav class="evt-steps" aria-label="Event creation steps">
        <ol class="evt-steps-list">
            <li class="evt-step {{ $current === 1 ? 'evt-step--active' : ($current > 1 ? 'evt-step--done' : '') }}">
                <span class="evt-step-num" aria-hidden="true">
                    @if ($current > 1)
                        <i class="fa-solid fa-check"></i>
                    @else
                        1
                    @endif
                </span>
                <span class="evt-step-label">How people join</span>
            </li>
            <li class="evt-steps-connector" aria-hidden="true"></li>
            <li class="evt-step {{ $current === 2 ? 'evt-step--active' : ($current > 2 ? 'evt-step--done' : '') }}">
                <span class="evt-step-num" aria-hidden="true">
                    @if ($current > 2)
                        <i class="fa-solid fa-check"></i>
                    @else
                        2
                    @endif
                </span>
                <span class="evt-step-label">Event details</span>
            </li>
            <li class="evt-steps-connector" aria-hidden="true"></li>
            <li class="evt-step {{ $current === 3 ? 'evt-step--active' : ($current > 3 ? 'evt-step--done' : '') }}">
                <span class="evt-step-num" aria-hidden="true">
                    @if ($current > 3)
                        <i class="fa-solid fa-check"></i>
                    @else
                        3
                    @endif
                </span>
                <span class="evt-step-label">Choose layout</span>
            </li>
            <li class="evt-steps-connector" aria-hidden="true"></li>
            <li class="evt-step {{ $current === 4 ? 'evt-step--active' : '' }}">
                <span class="evt-step-num" aria-hidden="true">4</span>
                <span class="evt-step-label">Customize &amp; publish</span>
            </li>
        </ol>
    </nav>
@endif
