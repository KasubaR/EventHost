@extends('layouts.site')

@section('title', 'Privacy Policy | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')

<section class="legal-hero">
    <div class="legal-hero-inner">
        <span class="legal-eyebrow">Legal</span>
        <h1>Privacy Policy</h1>
        <p>How Event Host collects, uses and protects the information you and your guests give us.</p>
        <div class="legal-updated">Last updated: [EFFECTIVE DATE]</div>
    </div>
</section>

@include('legal.partials.draft-notice')

<div class="legal-main">
    <div class="legal-grid">

        <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
                <li><a href="#who-we-are">Who we are</a></li>
                <li><a href="#what-we-collect">What we collect</a></li>
                <li><a href="#guest-data">Guest data you upload</a></li>
                <li><a href="#how-we-use">How we use it</a></li>
                <li><a href="#sharing">Who we share it with</a></li>
                <li><a href="#cookies">Cookies</a></li>
                <li><a href="#retention">How long we keep it</a></li>
                <li><a href="#your-rights">Your rights</a></li>
                <li><a href="#security">Security</a></li>
                <li><a href="#children">Children</a></li>
                <li><a href="#changes">Changes</a></li>
            </ol>
        </nav>

        <div class="legal-body">

            <section>
                <h2 id="who-we-are">1. Who we are</h2>
                <p>
                    Event Host is a digital invitation and RSVP platform operated by
                    <span class="legal-token">[COMPANY LEGAL NAME]</span>, registered in Zambia under
                    company number <span class="legal-token">[REGISTRATION NUMBER]</span>, with its
                    registered office at <span class="legal-token">[REGISTERED OFFICE ADDRESS]</span>,
                    Lusaka, Zambia.
                </p>
                <p>
                    In this policy, "we" and "us" mean that company. "You" means whoever is reading it —
                    a host with an account, or a guest who received an invitation from one.
                </p>
            </section>

            <section>
                <h2 id="what-we-collect">2. What we collect</h2>

                <h3>Account information</h3>
                <p>When you create a host account we store your name, email address, and optionally your phone number, company name and a profile photo.</p>

                <h3>Event content</h3>
                <p>Everything you enter when building an event: its title, description, date, venue and location, cover image, chosen invitation template and design settings, table layouts, and any photos uploaded to the event gallery.</p>

                <h3>Payment information</h3>
                <p>
                    Event credits are purchased through our payment provider, Lenco. Card and mobile-money
                    details are entered on their systems, not ours — <strong>we never see or store your full
                    card number or mobile-money PIN</strong>. We keep a record of each transaction: amount,
                    currency, reference, status and timestamp.
                </p>

                <h3>Technical information</h3>
                <p>We record the time and IP address of your most recent sign-in, and our servers keep standard access logs. API tokens issued to your account expire after seven days.</p>
            </section>

            <section>
                <h2 id="guest-data">3. Guest data you upload</h2>
                <p>
                    When you add or import a guest list, you give us other people's information — typically
                    names, email addresses and phone numbers. When those guests respond, we also store their
                    RSVP status, party size, dietary notes or other answers you asked for, their check-in
                    time if you scan them at the door, and any photos they upload to your event gallery.
                </p>
                <p>
                    <strong>You are responsible for that data.</strong> You must have a legitimate reason to
                    hold your guests' contact details and to pass them to us for the purpose of inviting
                    them. We process that data on your instructions — to deliver invitations, collect RSVPs
                    and run check-in — and for nothing else. We do not use your guest list to market to
                    those guests, and we do not sell it.
                </p>
            </section>

            <section>
                <h2 id="how-we-use">4. How we use it</h2>
                <ul>
                    <li>To create, host and display your event invitation pages</li>
                    <li>To deliver invitations and RSVP confirmations, and to collect responses</li>
                    <li>To run guest check-in, QR badges and table photo uploads</li>
                    <li>To process event credit purchases and keep a billing history</li>
                    <li>To send service email you cannot opt out of — email verification, password resets, and notices when your account email is changed</li>
                    <li>To send optional notification email, controlled by the toggles in your account settings</li>
                    <li>To publish a review on our homepage, but only one you wrote and submitted yourself, and only after we approve it</li>
                    <li>To keep the service secure, prevent abuse, and meet our legal obligations</li>
                </ul>
            </section>

            <section>
                <h2 id="sharing">5. Who we share it with</h2>
                <p>We do not sell personal information. We share it only with the service providers we need to run the platform:</p>
                <ul>
                    <li><strong>Lenco</strong> — payment processing for event credits</li>
                    <li><strong><span class="legal-token">[EMAIL PROVIDER]</span></strong> — delivery of invitation and account email</li>
                    <li><strong><span class="legal-token">[HOSTING PROVIDER]</span></strong> — servers and database storage</li>
                </ul>
                <p>
                    We may also disclose information where we are legally required to, or where it is
                    necessary to protect our rights or someone's safety.
                </p>
                <p>
                    Note that <strong>a published event invitation page is public</strong>. Anything you put
                    on it — including the venue, the date and any photos in the gallery — can be seen by
                    anyone with the link, and events listed on our Discover page can be found by anyone.
                </p>
            </section>

            <section>
                <h2 id="cookies">6. Cookies</h2>
                <p>
                    We use a small number of strictly necessary cookies, and load some assets from third
                    parties. This is set out in full in our <a href="{{ route('legal.cookies') }}">Cookie Policy</a>.
                </p>
            </section>

            <section>
                <h2 id="retention">7. How long we keep it</h2>
                <ul>
                    <li><strong>Account data</strong> — until you delete your account</li>
                    <li><strong>Event and guest data</strong> — until you delete the event, or delete your account</li>
                    <li><strong>Payment records</strong> — <span class="legal-token">[RETENTION PERIOD]</span>, as required for tax and accounting purposes, even after account deletion</li>
                    <li><strong>Access logs</strong> — <span class="legal-token">[RETENTION PERIOD]</span></li>
                </ul>
                <p>
                    Deleting your account from account settings removes your profile, your events and their
                    guest lists. Approved reviews you have published keep the name and context captured at
                    the time you submitted them, because they are part of a public page.
                </p>
            </section>

            <section>
                <h2 id="your-rights">8. Your rights</h2>
                <p>You can ask us to:</p>
                <ul>
                    <li>Give you a copy of the personal information we hold about you</li>
                    <li>Correct anything inaccurate — most of it you can edit yourself in account settings</li>
                    <li>Delete your account and its data</li>
                    <li>Stop sending you optional email, which you can also do from the notification toggles</li>
                </ul>
                <p>
                    If you are a <strong>guest</strong> rather than a host and want your details removed from
                    an event, the fastest route is to ask the host who invited you, since it is their guest
                    list. You can also email us and we will pass the request on.
                </p>
                <p>
                    To exercise any of these rights, email
                    <a href="mailto:hello@eventhost.co.zm">hello@eventhost.co.zm</a>. We aim to respond within
                    <span class="legal-token">[RESPONSE PERIOD]</span>.
                </p>
            </section>

            <section>
                <h2 id="security">9. Security</h2>
                <p>
                    Passwords are stored hashed, never in plain text. Traffic to the site is encrypted in
                    transit. Access to production data is limited to staff who need it. RSVP and check-in
                    links use unguessable tokens, and sensitive actions are rate-limited.
                </p>
                <p>
                    No system is perfectly secure. If we become aware of a breach affecting your data, we
                    will notify you and the relevant authority as required by law.
                </p>
            </section>

            <section>
                <h2 id="children">10. Children</h2>
                <p>
                    Event Host is not intended for children under <span class="legal-token">[MINIMUM AGE]</span>,
                    and we do not knowingly collect their information from them directly. A host may add a
                    child to a guest list; where that happens, the host is responsible for having the
                    permission of a parent or guardian.
                </p>
            </section>

            <section>
                <h2 id="changes">11. Changes</h2>
                <p>
                    We may update this policy as the service changes. The "last updated" date at the top of
                    this page always reflects the current version. If a change materially affects your
                    rights, we will tell you by email or with a notice in the app before it takes effect.
                </p>
            </section>

            @include('legal.partials.contact-card')
            @include('legal.partials.siblings')

        </div>
    </div>
</div>

@endsection
