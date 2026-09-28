@php
    $schedule = \App\Support\WeddingInvitationView::for($event, $invitation)->scheduleRows();
@endphp

@if (count($schedule) > 0)
    <section class="mg-section" id="programme">
        <h2 class="mg-title">Programme</h2>
        <ol class="mg-timeline">
            @foreach ($schedule as $row)
                <li class="mg-timeline-item">
                    @if ($row['time'] !== '')
                        <p class="mg-timeline-time">{{ $row['time'] }}</p>
                    @endif
                    <h3 class="mg-timeline-title">{{ $row['title'] }}</h3>
                    @if ($row['detail'] !== '')
                        <p class="mg-timeline-detail">{!! nl2br(e($row['detail'])) !!}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
