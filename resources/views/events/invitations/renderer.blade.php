@php
    $skinKey = in_array($invitation['skin'], ['classic', 'minimal', 'botanical-blush'], true) ? $invitation['skin'] : 'classic';
    $layoutVariantKey = \App\Support\InvitationLayoutVariant::normalize($invitation['layout_variant'] ?? null);
    $layoutClass = 'evt-layout-'.str_replace('_', '-', $layoutVariantKey);
@endphp

@if (! empty($isPreview))
    <div class="evt-preview-banner" role="status">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        {{ $previewLabel ?? 'Template preview — sample event only.' }}
    </div>
@endif

<div
    class="evt-invitation evt-skin-{{ $skinKey }} {{ $layoutClass }}@if ($invitation['effects']['animation_subtle']) evt-invitation--subtle-motion @endif"
    style="--evt-primary: {{ $invitation['theme']['primary'] }}; --evt-accent: {{ $invitation['theme']['accent'] }}; --evt-background: {{ $invitation['theme']['background'] }}; --evt-font-heading: {{ $invitation['theme']['font_heading_stack'] }}; --evt-font-body: {{ $invitation['theme']['font_body_stack'] }};"
>
    <section class="evt-public-inner evt-public-page evt-invitation-page">
        @include('events.invitations.partials.section-loop')
    </section>
</div>
