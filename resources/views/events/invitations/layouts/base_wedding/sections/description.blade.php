@php
    $wording = trim((string) $event->description);
    if ($wording === '') {
        $wording = 'We joyfully invite you to celebrate the union of two souls as they begin their forever journey together in love and laughter.';
    }
@endphp

<section class="bw-section bw-wording" id="invitation">
    <p class="bw-tag">You are cordially invited</p>
    <div class="bw-body bw-body--lead">{!! nl2br(e($wording)) !!}</div>
</section>
