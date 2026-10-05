@php
    $chapters = \App\Support\WeddingInvitationView::for($event, $invitation)->storyChapters();
@endphp

@if (count($chapters) > 0)
    <section class="mg-section" id="story">
        <h2 class="mg-title">Love Story</h2>
        @foreach ($chapters as $chapter)
            <div class="mg-story-row">
                <p class="mg-story-text">{!! nl2br(e($chapter['text'])) !!}</p>
                <figure class="mg-story-photo">
                    <img src="{{ $chapter['photo'] }}" alt="{{ \App\Support\InvitationMediaUrl::photoAlt($event->name) }}" loading="lazy" width="480" height="480">
                </figure>
            </div>
        @endforeach
    </section>
@endif
