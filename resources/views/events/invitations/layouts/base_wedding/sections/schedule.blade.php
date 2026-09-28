@php
    $schedule = \App\Support\WeddingInvitationView::for($event, $invitation)->scheduleRows();
@endphp

@if (count($schedule) > 0)
    <section class="bw-section bw-schedule">
        <p class="bw-tag">Order of the Day</p>
        <h2 class="bw-title">The <em>Programme</em></h2>
        <ol class="bw-programme">
            @foreach ($schedule as $row)
                <li class="bw-programme-row">
                    <p class="bw-programme-time">{{ $row['time'] }}</p>
                    <div class="bw-programme-text">
                        <p class="bw-programme-title">{{ $row['title'] }}</p>
                        @if ($row['detail'] !== '')
                            <p class="bw-programme-detail">{!! nl2br(e($row['detail'])) !!}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@endif
