@php
    $story = trim((string) ($invitation['content']['story'] ?? ''));
@endphp

@if ($story !== '')
    <section class="evt-inv-section evt-inv-story">
        <h2 class="evt-inv-story-heading">Our story</h2>
        <div class="evt-inv-story-body">{!! nl2br(e($story)) !!}</div>
    </section>
@endif
