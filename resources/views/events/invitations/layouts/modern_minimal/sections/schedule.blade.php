@php
    $schedule = \App\Support\WeddingInvitationView::for($event, $invitation)->scheduleRows();
@endphp

@if (count($schedule) > 0)
    <section class="mm-section mm-schedule">
        <h2 class="mm-section-title">Programme</h2>
        <ol class="mm-timeline">
            @foreach ($schedule as $row)
                <li class="mm-timeline-row">
                    <p class="mm-timeline-time">{{ $row['time'] }}</p>
                    <div>
                        <p class="mm-timeline-title">{{ $row['title'] }}</p>
                        @if ($row['detail'] !== '')
                            <p class="mm-timeline-detail">{!! nl2br(e($row['detail'])) !!}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@endif
