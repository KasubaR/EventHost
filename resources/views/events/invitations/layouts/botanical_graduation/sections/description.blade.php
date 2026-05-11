@if ($event->description)
    <div class="message-section evt-bg-message">
        <div class="message-inner">
            <p class="section-eyebrow">A note from the host</p>
            <h2 class="section-title evt-bg-section-title-h2">Thank you</h2>
            <div class="message-body evt-inv-description">{!! nl2br(e($event->description)) !!}</div>
        </div>
    </div>
@endif
