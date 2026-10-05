@php
    $chapters = \App\Support\WeddingInvitationView::for($event, $invitation)->storyChapters();
@endphp

@if (count($chapters) > 0)
    <section class="db-section" id="story">
        <h2 class="db-title">Our Story</h2>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')
        <div class="db-story">
            @foreach ($chapters as $chapter)
                <div class="db-story-row">
                    <p class="db-story-text">{!! nl2br(e($chapter['text'])) !!}</p>
                    <figure class="db-story-photo">
                        <img src="{{ $chapter['photo'] }}" alt="{{ \App\Support\InvitationMediaUrl::photoAlt($event->name) }}" loading="lazy" width="480" height="480">
                    </figure>
                </div>
            @endforeach
        </div>
    </section>
@endif
