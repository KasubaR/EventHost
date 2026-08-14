@extends('layouts.site', ['hideSiteFooter' => true, 'hideSiteHeader' => true])

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
        @isset($fromEvent)
            <a href="{{ route('events.choose-template', $fromEvent) }}" class="tpl-preview-bar-back">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                <span>Back to layouts</span>
            </a>
        @else
            {{-- The library listing needs an account, so send guests back to the homepage strip. --}}
            <a href="{{ auth()->check() ? route('templates.index') : route('home').'#templates' }}" class="tpl-preview-bar-back">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                <span>Templates</span>
            </a>
        @endisset

        <span class="tpl-preview-bar-name">{{ $invitation_template->name }}</span>

        <div class="tpl-preview-bar-actions">
            @if (auth()->user()?->canUseInvitationTemplate($invitation_template))
                @isset($fromEvent)
                    {{-- Inside the wizard, apply to the event in hand rather than starting a new one --}}
                    <form method="post" action="{{ route('events.choose-template.update', $fromEvent) }}">
                        @csrf
                        @method('patch')
                        <input type="hidden" name="invitation_template_id" value="{{ $invitation_template->id }}">
                        <button type="submit" class="btn-primary tpl-btn-small">
                            <i class="fa-solid fa-check" aria-hidden="true"></i> Use this layout
                        </button>
                    </form>
                @else
                    <a href="{{ route('events.create', ['template' => $invitation_template->slug]) }}" class="btn-primary tpl-btn-small">
                        <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Use this template
                    </a>
                @endisset
            @elseif (! auth()->check())
                {{-- Reached from the public homepage strip — sign up before picking a template. --}}
                <a href="{{ route('register') }}" class="btn-primary tpl-btn-small">
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Use this template
                </a>
            @else
                <span class="tpl-preview-bar-lock">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i> Requires {{ $invitation_template->requiredTier()->label() }}
                </span>
                <a href="{{ \App\Support\BillingPlan::checkoutUrlForTier($invitation_template->requiredTier()) }}" class="tpl-preview-bar-upgrade tpl-btn-small">Upgrade to {{ $invitation_template->requiredTier()->label() }}</a>
            @endif
        </div>
    </div>

    @include('events.invitations.renderer', ['event' => $event, 'rsvpOpen' => $rsvpOpen, 'rsvpPublicAvailable' => $rsvpPublicAvailable, 'invitation' => $invitation, 'isPreview' => true])

@endsection
