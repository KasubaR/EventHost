<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Event Host — Create Beautiful Digital Invitations')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo/EventHost Logo_Icon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
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
</body>
</html>
