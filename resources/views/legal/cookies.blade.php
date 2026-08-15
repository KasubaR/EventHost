@extends('layouts.site')

@section('title', 'Cookie Policy | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')

<section class="legal-hero">
    <div class="legal-hero-inner">
        <span class="legal-eyebrow">Legal</span>
        <h1>Cookie Policy</h1>
        <p>What we store in your browser, and what loads from other companies when you visit.</p>
        <div class="legal-updated">Last updated: {{ now()->format('F j, Y') }}</div>
    </div>
</section>

<div class="legal-main">
    <div class="legal-grid">

        <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
                <li><a href="#summary">The short version</a></li>
                <li><a href="#our-cookies">Cookies we set</a></li>
                <li><a href="#third-parties">Third-party requests</a></li>
                <li><a href="#no-tracking">What we don't do</a></li>
                <li><a href="#managing">Managing cookies</a></li>
                <li><a href="#changes">Changes</a></li>
            </ol>
        </nav>

        <div class="legal-body">

            <section>
                <h2 id="summary">1. The short version</h2>
                <p>
                    Event Host sets only the cookies it needs to work: one to keep you signed in, one to
                    protect forms against cross-site request forgery, and one if you tick "remember me".
                    There are no advertising cookies and no analytics cookies.
                </p>
                <p>
                    Because all of them are strictly necessary, we do not show a cookie consent banner —
                    there is nothing optional to consent to. If we ever add analytics or marketing cookies,
                    we will ask first.
                </p>
            </section>

            <section>
                <h2 id="our-cookies">2. Cookies we set</h2>

                <h3>Session cookie</h3>
                <p>
                    Keeps you signed in as you move between pages, and links your browser to your server-side
                    session. Expires when the session ends. Without it you would be logged out on every click.
                </p>

                <h3>CSRF token (<code>XSRF-TOKEN</code>)</h3>
                <p>
                    A security cookie that lets us verify a form was submitted from our own site and not by a
                    malicious page elsewhere. Expires with the session.
                </p>

                <h3>Remember-me cookie</h3>
                <p>
                    Set only if you tick "Remember me" when signing in, so you stay signed in after closing
                    your browser. Lasts <span class="legal-token">[REMEMBER-ME DURATION]</span>. Signing out
                    removes it.
                </p>
            </section>

            <section>
                <h2 id="third-parties">3. Third-party requests</h2>
                <p>
                    Some parts of the site load files from other companies. These are not cookies we set, but
                    the request itself tells that company your IP address and browser, and they may set
                    cookies of their own under their policies.
                </p>
                <ul>
                    <li><strong>Google Fonts</strong> — the DM Sans and Outfit typefaces, on every page</li>
                    <li><strong>Cloudflare (cdnjs)</strong> — the Font Awesome icon set, on every page</li>
                    <li><strong>Unsplash</strong> — stock imagery used in some page designs</li>
                    <li><strong>Lenco</strong> — the checkout flow, when you buy event credits</li>
                    <li><strong>YouTube</strong> — video testimonials on the homepage. These are <strong>click-to-play</strong>: nothing loads from YouTube until you press play on a video, so no YouTube cookies are set if you never do</li>
                </ul>
            </section>

            <section>
                <h2 id="no-tracking">4. What we don't do</h2>
                <ul>
                    <li>We do not run advertising or retargeting pixels</li>
                    <li>We do not sell or share cookie data with data brokers</li>
                    <li>We do not track you across other websites</li>
                    <li>We do not build an advertising profile from your browsing</li>
                </ul>
            </section>

            <section>
                <h2 id="managing">5. Managing cookies</h2>
                <p>
                    Every major browser lets you view, delete and block cookies from its settings, usually
                    under "Privacy". You can also browse in a private or incognito window, which clears
                    cookies when you close it.
                </p>
                <p>
                    Blocking our cookies will stop you signing in and will cause form submissions to fail,
                    because the sign-in and CSRF cookies are what make those work.
                </p>
            </section>

            <section>
                <h2 id="changes">6. Changes</h2>
                <p>
                    If we add or remove a cookie, we will update this page and change the "last updated" date
                    at the top. Adding any non-essential cookie would also mean asking for your consent first.
                </p>
            </section>

            @include('legal.partials.contact-card')
            @include('legal.partials.siblings')

        </div>
    </div>
</div>

@endsection
