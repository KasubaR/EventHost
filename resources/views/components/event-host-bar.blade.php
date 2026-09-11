@props([
    'event' => null,
    'tagline' => 'Create beautiful event invitations & track RSVPs in one place.',
])

{{-- The one EventHost bar shown on public-facing event pages — was
     copy-pasted into four views before this component existed. Hidden once
     the event owner has paid for the "remove branding" add-on. See
     plans/remove-branding.md. --}}
@unless (($event)?->branding_removed)
    <div class="evt-host-bar">
        <a href="{{ url('/') }}" class="evt-host-bar-logo" target="_blank" rel="noopener noreferrer">
            <img src="{{ asset('images/logo/EventHost Logo_Icon.svg') }}" alt="{{ config('app.name') }}" width="22" height="22">
            <span>{{ config('app.name') }}</span>
        </a>
        <p class="evt-host-bar-tagline">{{ $tagline }}</p>
        <a href="{{ url('/') }}" class="evt-host-bar-cta" target="_blank" rel="noopener noreferrer">
            Get started free <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>
@endunless
