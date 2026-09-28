@php
    $story = \App\Support\WeddingInvitationView::for($event, $invitation)->story();
@endphp

@if ($story !== '')
    <section class="bw-section bw-story">
        <p class="bw-tag">Our Story</p>
        <h2 class="bw-title">How it all <em>began</em></h2>
        <div class="bw-body">{!! nl2br(e($story)) !!}</div>
    </section>
@endif
