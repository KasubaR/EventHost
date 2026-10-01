@php
    $schedule = \App\Support\WeddingInvitationView::for($event, $invitation)->scheduleRows();
@endphp

@if (count($schedule) > 0)
    <section class="wi-schedule-section wi-reveal" data-wi-reveal>
        <p class="wi-section-tag">Order of the Day</p>
        <h2 class="wi-section-title">The <em>Programme</em></h2>
        <div class="wi-orn" aria-hidden="true">· ◆ ·</div>
        <ol class="wi-timeline">
            @foreach ($schedule as $row)
                <li class="wi-timeline-item">
                    @if ($row['time'] !== '')
                        <p class="wi-timeline-time">{{ $row['time'] }}</p>
                    @endif
                    <p class="wi-timeline-title">{{ $row['title'] }}</p>
                    @if ($row['detail'] !== '')
                        <p class="wi-timeline-detail">{!! nl2br(e($row['detail'])) !!}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
