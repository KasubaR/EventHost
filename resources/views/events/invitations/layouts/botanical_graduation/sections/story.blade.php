@php
    $story = trim((string) ($invitation['content']['story'] ?? ''));
@endphp

@if ($story !== '')
    <div class="message-section evt-bg-message evt-inv-story evt-inv-story--botanical">
        <div class="message-inner">
            <p class="section-eyebrow">Our story</p>
            <h2 class="section-title evt-bg-section-title-h2">Together</h2>
            <div class="message-body evt-inv-story-body">{!! nl2br(e($story)) !!}</div>
        </div>
    </div>
@endif
