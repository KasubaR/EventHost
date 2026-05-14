@php
    $story = trim((string) ($invitation['content']['story'] ?? ''));
@endphp

@if ($story !== '')
    <section class="bfa-section bfa-quote-section" id="message">
        <div class="bfa-section-inner bfa-quote-inner">
            <div class="bfa-section-label">Heart of the gathering</div>
            <blockquote class="bfa-quote-block">
                <p class="bfa-quote-script">&ldquo;{!! nl2br(e($story)) !!}&rdquo;</p>
            </blockquote>
        </div>
    </section>
@endif
