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
    class="evt-invitation evt-skin-{{ $skinKey }} {{ $layoutClass }}@if ($invitation['effects']['animation_subtle']) evt-invitation--subtle-motion @endif"
    style="--evt-primary: {{ $invitation['theme']['primary'] }}; --evt-accent: {{ $invitation['theme']['accent'] }}; --evt-background: {{ $invitation['theme']['background'] }}; --evt-font-heading: {{ $invitation['theme']['font_heading_stack'] }}; --evt-font-body: {{ $invitation['theme']['font_body_stack'] }};"
>
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

    @if (empty($isPreview) && isset($event) && ! empty($invitation['effects']['audio_track']))
        <p class="evt-inv-audio-report"><a href="{{ route('audio-report.show', $event) }}" rel="nofollow">Report this music</a></p>
    @endif

    @if ($floatingAudioPath)
        <button type="button" class="evt-inv-audio-play evt-inv-audio-play--floating" data-inv-audio-play data-audio-src="{{ asset('storage/'.$floatingAudioPath) }}">
            <i class="fa-solid fa-music" aria-hidden="true"></i>
            <span class="evt-inv-audio-label">Play music</span>
        </button>
    @endif
</div>
