{{-- The ticket icon (public/images/icon/blue-ticket.svg). Use this instead of `fa-ticket` everywhere.
     Sized in `em`, so it scales with the surrounding font-size like the Font Awesome glyph it replaces. --}}
<img src="{{ asset('images/icon/blue-ticket.svg') }}" alt="" aria-hidden="true" {{ $attributes->merge(['class' => 'ticket-icon']) }}>
