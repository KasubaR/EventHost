@php
    $schedule = \App\Support\WeddingInvitationView::for($event, $invitation)->scheduleRows();
@endphp

@if (count($schedule) > 0)
    <section class="db-section" id="programme">
        <h2 class="db-title">Programme</h2>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')
        <ol class="db-timeline">
            @foreach ($schedule as $row)
                <li class="db-timeline-item">
                    @if ($row['time'] !== '')
                        <p class="db-timeline-time">{{ $row['time'] }}</p>
                    @endif
                    <h3 class="db-timeline-title">{{ $row['title'] }}</h3>
                    @if ($row['detail'] !== '')
                        <p class="db-timeline-detail">{!! nl2br(e($row['detail'])) !!}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
