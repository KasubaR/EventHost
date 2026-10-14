@php
    $chapters = \App\Support\WeddingInvitationView::for($event, $invitation)->storyChapters();
@endphp

@if (count($chapters) > 0)
    <section class="rb-section rb-section--tint" id="story">
        <div class="rb-wrap">
            <h2 class="rb-title">Our Love Story</h2>
            @include('events.invitations.layouts.wedding_royal_blue.partials.ornament')
            <div class="rb-story">
                @foreach ($chapters as $chapter)
                    <article class="rb-story-card">
                        <figure class="rb-story-photo">
                            <img src="{{ $chapter['photo'] }}" alt="{{ \App\Support\InvitationMediaUrl::photoAlt($event->name) }}" loading="lazy" width="480" height="640">
                        </figure>
                        <p class="rb-story-text">{!! nl2br(e($chapter['text'])) !!}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif
