@php
    $schedule = \App\Support\WeddingInvitationView::for($event, $invitation)->scheduleRows();
@endphp

@if (count($schedule) > 0)
    <section class="rb-section rb-section--tint" id="programme">
        <div class="rb-wrap rb-wrap--narrow">
            <h2 class="rb-title">Programme</h2>
            @include('events.invitations.layouts.wedding_royal_blue.partials.ornament')
            <ol class="rb-timeline">
                @foreach ($schedule as $row)
                    <li class="rb-timeline-item">
                        @if ($row['time'] !== '')
                            <p class="rb-timeline-time">{{ $row['time'] }}</p>
                        @endif
                        <h3 class="rb-timeline-title">{{ $row['title'] }}</h3>
                        @if ($row['detail'] !== '')
                            <p class="rb-timeline-detail">{!! nl2br(e($row['detail'])) !!}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endif
