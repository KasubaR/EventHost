@php
    $story = trim((string) ($invitation['content']['story'] ?? ''));
@endphp

@if ($story !== '')
    <section class="mm-section">
        <h2 class="mm-section-title">Our Story</h2>
        <p class="mm-story-text">{!! nl2br(e($story)) !!}</p>
    </section>
@endif
