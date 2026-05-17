@php
    $schedule = array_values($invitation['content']['schedule'] ?? []);
    $icons = ['fa-wine-glass', 'fa-ring', 'fa-champagne-glasses', 'fa-utensils', 'fa-music', 'fa-star'];
@endphp

@if (count($schedule) > 0)
    <section class="wi2-timeline-section wi2-reveal" data-wi2-reveal>
        <div class="wi2-section-header">
            <span class="wi2-section-kicker">The Day's Programme</span>
            <h2 class="wi2-section-heading">An <em>Evening</em> to Remember</h2>
        </div>
        <div class="wi2-timeline">
            @foreach ($schedule as $idx => $row)
                @php
                    $time = trim((string) ($row['time'] ?? ''));
                    $title = trim((string) ($row['title'] ?? ''));
                    $detail = trim((string) ($row['detail'] ?? ''));
                @endphp
                @if ($title !== '')
                    <div class="wi2-tl-item wi2-reveal" data-wi2-reveal>
                        <div class="wi2-tl-content">
                            @if ($time !== '')
                                <p class="wi2-tl-time">{{ $time }}</p>
                            @endif
                            <p class="wi2-tl-title">{{ $title }}</p>
                            @if ($detail !== '')
                                <p class="wi2-tl-desc">{{ $detail }}</p>
                            @endif
                        </div>
                        <div class="wi2-tl-dot" aria-hidden="true">
                            <i class="fa-solid {{ $icons[$idx % count($icons)] }}" aria-hidden="true"></i>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </section>
@endif
