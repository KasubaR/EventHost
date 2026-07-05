@extends('layouts.site', ['hideSiteFooter' => true])

@foreach ($invitation['theme']['google_font_families'] as $gf)
    @push('head')
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ $gf }}&display=swap">
    @endpush
@endforeach

@push('head')
    <link rel="stylesheet" href="{{ asset('css/templates.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/events-invitation.css') }}">
    @php $layoutCss = \App\Support\InvitationLayoutVariant::cssFile($invitation['layout_variant'] ?? \App\Support\InvitationLayoutVariant::STANDARD); @endphp
    @if ($layoutCss)
        <link rel="stylesheet" href="{{ asset('css/'.$layoutCss) }}">
    @endif
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js" defer></script>
    <script src="{{ asset('js/invitation-public.js') }}" defer></script>
@endpush

@section('title', $invitation_template->name.' | Templates | '.config('app.name'))

@section('content')

    <div class="tpl-preview-bar" role="navigation" aria-label="Template preview">
        <a href="{{ route('templates.index') }}" class="tpl-preview-bar-back">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            <span>Templates</span>
        </a>

        <span class="tpl-preview-bar-name">{{ $invitation_template->name }}</span>

        <div class="tpl-preview-bar-actions">
            @if (auth()->user()?->canUseInvitationTemplate($invitation_template))
                <a href="{{ route('events.create', ['template' => $invitation_template->slug]) }}" class="btn-primary tpl-btn-small">
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Use this template
                </a>
            @else
                <span class="tpl-preview-bar-lock">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i> Requires {{ $invitation_template->requiredTier()->label() }}
                </span>
                <a href="{{ url('/') }}#pricing" class="tpl-preview-bar-upgrade tpl-btn-small">View plans</a>
            @endif
        </div>
    </div>

    @include('events.invitations.renderer', ['event' => $event, 'rsvpOpen' => $rsvpOpen, 'rsvpPublicAvailable' => $rsvpPublicAvailable, 'invitation' => $invitation, 'isPreview' => true])

@endsection
