<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- "js" lets CSS hide things only when script will reveal them; "js-stalled" undoes that if invitation-public.js
         has not run after 4s (slow link, blocked, errored), so content is never stuck invisible. --}}
    <script>document.documentElement.classList.add('js');setTimeout(function(){if(!document.documentElement.hasAttribute('data-inv-ready')){document.documentElement.classList.add('js-stalled');}},4000);</script>
    {{-- @yield, not {{ }}: @section('title', $value) already escapes $value, so {{ }} here double-escapes "&" into a visible "&amp;". --}}
    <title>@yield('title', 'Event Host | Create Beautiful Digital Invitations')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo/EventHost Logo_Icon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    @unless ($noUnsplashPreconnect ?? false)
        <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    @endunless
    {{-- Guest pages ($guestPage: the invitation, the RSVP pages, previews) must not wait on a third-party host to paint.
         Their icons come from one small local stylesheet (public/css/guest-icons.css, built by icons:build-guest-css) and the
         web fonts load without blocking (media="print" until loaded; the text shows in a fallback face meanwhile). An admin
         acting as a client keeps the full icon set, because the acting-as banner uses an icon that is not in the guest set.
         Every other page is unchanged. --}}
    @if ($guestPage ?? false)
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
        <noscript><link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
        @if (session()->has('acting_as'))
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
        @else
            <link rel="stylesheet" href="{{ asset('css/guest-icons.css') }}">
        @endif
    @else
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    @endif
    <link rel="stylesheet" href="{{ asset('css/global.css') }}">
    <link rel="stylesheet" href="{{ asset('css/account-components.css') }}">
    @vite(['resources/css/app.css'])
    @stack('head')
</head>
<body>

@if (! ($hideSiteHeader ?? false))
    <x-site-header />
@endif

<main>
    @yield('content')
</main>

@if (! ($hideSiteFooter ?? false))
    <x-site-footer />
@endif

<script src="{{ asset('js/homepage.js') }}" defer></script>
@stack('scripts')
<x-acting-as-banner />
</body>
</html>
