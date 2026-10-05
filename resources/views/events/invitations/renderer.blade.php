@php
    $skinKey = in_array($invitation['skin'], ['classic', 'minimal', 'botanical-blush'], true) ? $invitation['skin'] : 'classic';
    $layoutVariantKey = \App\Support\InvitationLayoutVariant::normalize($invitation['layout_variant'] ?? null);
    $layoutClass = 'evt-layout-'.str_replace('_', '-', $layoutVariantKey);
    $hasSectionNav = \App\Support\InvitationLayoutVariant::hasSectionNav($layoutVariantKey);

    // Sections are rendered up front so the nav only links to ones that produced markup.
    $renderedSections = [];
    foreach ($invitation['sections'] as $section) {
        if (! ($section['visible'] ?? true)
            || in_array($section['type'], \App\Support\InvitationLayoutVariant::blockedSections($invitation['layout_variant'] ?? \App\Support\InvitationLayoutVariant::STANDARD), true)) {
            continue;
        }

        $sectionHtml = trim($__env->make(
            'events.invitations.partials.section',
            array_merge(\Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path', 'renderedSections']), ['section' => $section])
        )->render());

        if ($sectionHtml !== '') {
            $renderedSections[] = ['type' => $section['type'], 'html' => $sectionHtml];
        }
    }

    $sectionNavItems = \App\Support\InvitationSectionNav::items(
        $layoutVariantKey,
        array_column($renderedSections, 'type'),
        isset($event) && $event->isTicketed()
    );
    $anchoredTypes = [];

    // "Title by Artist", or whichever half was given. The music button is drawn by the layout (or the
    // floating card), so the caption is handed to invitation-public.js on the root instead.
    $audioCaption = collect([
        $invitation['effects']['audio_title'] ?? null,
        $invitation['effects']['audio_artist'] ?? null,
    ])->filter()->implode(' by ');

    // These layouts draw the music button inside their own hero; every other layout gets a floating one.
    $layoutsWithOwnAudioButton = ['standard', 'base_wedding', 'beauty_for_ashes', 'pro_magazine'];
    $floatingAudioPath = in_array($layoutVariantKey, $layoutsWithOwnAudioButton, true)
        ? null
        : ($invitation['effects']['audio_track'] ?? null);
@endphp

@if ($sectionNavItems !== [])
    @push('head')
        <link rel="stylesheet" href="{{ asset('css/events-invitation-section-nav.css') }}">
    @endpush
@endif

@if (! empty($isPreview))
    <div class="evt-preview-banner" role="status">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        {{ $previewLabel ?? 'Template preview: sample event only.' }}
    </div>
@endif

<div
    @if (! empty($invitation['effects']['audio_track']) && $audioCaption !== '')
        data-audio-caption="{{ $audioCaption }}"
    @endif
    @if (empty($isPreview) && isset($event) && ! empty($invitation['effects']['audio_track']))
        data-audio-report-url="{{ route('audio-report.show', $event) }}"
    @endif
    class="evt-invitation evt-skin-{{ $skinKey }} {{ $layoutClass }}@if ($invitation['effects']['animation_subtle']) evt-invitation--subtle-motion @endif"
    style="--evt-primary: {{ $invitation['theme']['primary'] }}; --evt-accent: {{ $invitation['theme']['accent'] }}; --evt-background: {{ $invitation['theme']['background'] }}; --evt-font-heading: {{ $invitation['theme']['font_heading_stack'] }}; --evt-font-body: {{ $invitation['theme']['font_body_stack'] }};"
>
    {{-- Only when something here needs scripts; RSVP and the page itself work without them. --}}
    @if (! empty($invitation['media']['gallery']) || ! empty($invitation['effects']['audio_track']) || ! empty($invitation['effects']['video_background']))
        <noscript>
            <p class="evt-inv-noscript">Some extras, like the photo slider and music, need JavaScript. Everything you need to RSVP works without it.</p>
        </noscript>
    @endif

    @if ($sectionNavItems !== [])
        @include('events.invitations.partials.section-nav', ['items' => $sectionNavItems])
    @endif

    <section class="evt-public-inner evt-public-page evt-invitation-page">
        @foreach ($renderedSections as $rendered)
            @if ($hasSectionNav && ! in_array($rendered['type'], $anchoredTypes, true))
                @php $anchoredTypes[] = $rendered['type']; @endphp
                <div id="{{ \App\Support\InvitationSectionNav::anchorId($rendered['type']) }}" class="evt-inv-anchor">
                    {!! $rendered['html'] !!}
                </div>
            @else
                {!! $rendered['html'] !!}
            @endif
        @endforeach
    </section>

    @if ($floatingAudioPath)
        <div class="evt-inv-audio-floating">
            <button type="button" class="evt-inv-audio-play" data-inv-audio-play data-audio-src="{{ asset('storage/'.$floatingAudioPath) }}">
                <i class="fa-solid fa-music" aria-hidden="true"></i>
                <span class="evt-inv-audio-label">Play music</span>
            </button>
        </div>
    @endif
</div>
