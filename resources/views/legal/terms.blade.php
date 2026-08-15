@extends('layouts.site')

@section('title', 'Terms of Service | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')

<section class="legal-hero">
    <div class="legal-hero-inner">
        <span class="legal-eyebrow">Legal</span>
        <h1>Terms of Service</h1>
        <p>The agreement between you and Event Host when you create an account and host an event.</p>
        <div class="legal-updated">Last updated: [EFFECTIVE DATE]</div>
    </div>
</section>

@include('legal.partials.draft-notice')

<div class="legal-main">
    <div class="legal-grid">

        <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
                <li><a href="#agreement">The agreement</a></li>
                <li><a href="#accounts">Accounts</a></li>
                <li><a href="#credits">Event credits</a></li>
                <li><a href="#refunds">Refunds</a></li>
                <li><a href="#your-content">Your content</a></li>
                <li><a href="#guest-lists">Guest lists</a></li>
                <li><a href="#acceptable-use">Acceptable use</a></li>
                <li><a href="#reviews">Reviews</a></li>
                <li><a href="#availability">Availability</a></li>
                <li><a href="#suspension">Suspension and closure</a></li>
                <li><a href="#liability">Liability</a></li>
                <li><a href="#law">Governing law</a></li>
                <li><a href="#changes">Changes</a></li>
            </ol>
        </nav>

        <div class="legal-body">

            <section>
                <h2 id="agreement">1. The agreement</h2>
                <p>
                    These terms are a contract between you and
                    <span class="legal-token">[COMPANY LEGAL NAME]</span> ("Event Host", "we", "us"). By
                    creating an account or using the service you accept them. If you are agreeing on behalf
                    of a company, you confirm you are authorised to bind that company.
                </p>
                <p>
                    Our <a href="{{ route('legal.privacy') }}">Privacy Policy</a> and
                    <a href="{{ route('legal.cookies') }}">Cookie Policy</a> form part of this agreement.
                </p>
            </section>

            <section>
                <h2 id="accounts">2. Accounts</h2>
                <p>
                    You must be at least <span class="legal-token">[MINIMUM AGE]</span> to hold an account,
                    give accurate details, and verify your email address before you can use the dashboard.
                </p>
                <p>
                    You are responsible for keeping your password secure and for everything done through
                    your account. Tell us immediately if you think someone else has access to it. Do not
                    share your account with other people, and do not create an account on someone else's
                    behalf without their permission.
                </p>
            </section>

            <section>
                <h2 id="credits">3. Event credits</h2>
                <p>
                    Event Host is paid for with <strong>event credits</strong>. Creating an event costs one
                    credit, which is deducted when the event is created. Credits are bought through our
                    payment provider, Lenco, in the currency shown at checkout.
                </p>
                <ul>
                    <li>Credits have no cash value and cannot be exchanged for money</li>
                    <li>Credits are tied to your account and cannot be transferred to another user</li>
                    <li>Credits expire <span class="legal-token">[CREDIT EXPIRY, OR "do not expire"]</span></li>
                    <li>Unused credits are forfeited if you delete your account</li>
                    <li>Prices may change; a change never affects credits you have already bought</li>
                </ul>
                <p>
                    We may also grant credits manually — for support cases or promotions. Granted credits
                    work the same way but are not refundable in cash.
                </p>
            </section>

            <section>
                <h2 id="refunds">4. Refunds</h2>
                <p>
                    <span class="legal-token">[REFUND POLICY — set out the window, the conditions, and how
                    to request one. This clause must be written before you take live payments.]</span>
                </p>
                <p>
                    Nothing in this section limits any refund right you have under Zambian consumer law
                    that cannot be excluded by contract.
                </p>
            </section>

            <section>
                <h2 id="your-content">5. Your content</h2>
                <p>
                    You keep ownership of everything you upload — event details, cover images, gallery
                    photos, guest lists and text. You grant us a non-exclusive, worldwide, royalty-free
                    licence to store, reproduce and display that content, strictly for the purpose of
                    operating the service: rendering your invitation page, sending your invitations and
                    showing your event where you have chosen to make it public.
                </p>
                <p>
                    You confirm you have the rights to what you upload — including any photographs, and the
                    permission of anyone identifiable in them.
                </p>
            </section>

            <section>
                <h2 id="guest-lists">6. Guest lists</h2>
                <p>
                    You decide whose contact details go on your guest list and why. You confirm that you are
                    entitled to hold those details and to provide them to us so we can send invitations on
                    your behalf. We act on your instructions in relation to that data.
                </p>
                <p>
                    You must not use Event Host to send unsolicited bulk messages. Invitations are for people
                    who would reasonably expect to hear from you about your event.
                </p>
                <p>
                    If a guest asks us to remove their details, we will normally refer them to you, and may
                    remove the record ourselves where we are legally required to.
                </p>
            </section>

            <section>
                <h2 id="acceptable-use">7. Acceptable use</h2>
                <p>You may not use Event Host to:</p>
                <ul>
                    <li>Break the law, or promote an event that is itself unlawful</li>
                    <li>Post content that is defamatory, hateful, harassing, sexually explicit, or that infringes someone else's rights</li>
                    <li>Impersonate another person, business or organisation</li>
                    <li>Send spam, run a scam, or advertise an event that will not take place</li>
                    <li>Probe, scrape, overload or reverse-engineer the service, or bypass its rate limits, access controls or payment flow</li>
                    <li>Upload malware, or anything designed to interfere with the service or its users</li>
                    <li>Resell or white-label the service without our written agreement</li>
                </ul>
            </section>

            <section>
                <h2 id="reviews">8. Reviews</h2>
                <p>
                    If you review an event you hosted, you confirm the review is your own genuine opinion.
                    We review submissions before publishing and may decline or remove any of them. Editing
                    an approved review returns it to pending and removes it from the homepage until it is
                    approved again. Publishing a review is at our discretion, and we may unpublish one at
                    any time.
                </p>
            </section>

            <section>
                <h2 id="availability">9. Availability</h2>
                <p>
                    We work to keep Event Host available, but we do not promise uninterrupted service. We may
                    take the platform down for maintenance, and features may change, be added or be removed.
                </p>
                <p>
                    <strong>Delivery of email is not guaranteed.</strong> Invitations and notifications can be
                    delayed, filtered or blocked by a recipient's mail provider. For an event that matters,
                    confirm important guests have received their invitation by another route.
                </p>
                <p>
                    Support is provided by email at
                    <a href="mailto:hello@eventhost.co.zm">hello@eventhost.co.zm</a> during
                    <span class="legal-token">[SUPPORT HOURS]</span>, and we aim to reply within one
                    business day. That is a target, not a contractual commitment.
                </p>
            </section>

            <section>
                <h2 id="suspension">10. Suspension and closure</h2>
                <p>
                    You can delete your account at any time from account settings. Deletion is permanent and
                    removes your events and guest lists.
                </p>
                <p>
                    We may suspend or close an account that breaches these terms, that is being used
                    fraudulently, or where we are required to by law. Where it is reasonable to do so we
                    will warn you first and give you a chance to put things right. If we close your account
                    for a breach, unused credits are forfeited.
                </p>
            </section>

            <section>
                <h2 id="liability">11. Liability</h2>
                <p>
                    The service is provided "as is". To the fullest extent the law allows, we exclude implied
                    warranties, and we are not liable for indirect or consequential loss, lost profit, lost
                    business, or loss of goodwill.
                </p>
                <p>
                    Where we are liable, our total liability to you is limited to
                    <span class="legal-token">[LIABILITY CAP — e.g. the amount you paid us in the 12 months
                    before the claim]</span>.
                </p>
                <p>
                    Nothing here limits liability that cannot lawfully be limited, including for death or
                    personal injury caused by our negligence, or for fraud.
                </p>
                <p>
                    <strong>We are not a party to your event.</strong> We do not organise it, vet your guests,
                    or take responsibility for what happens at it.
                </p>
            </section>

            <section>
                <h2 id="law">12. Governing law</h2>
                <p>
                    These terms are governed by the laws of Zambia, and the courts of Zambia have exclusive
                    jurisdiction over any dispute arising from them.
                    <span class="legal-token">[Confirm this, and add any dispute-resolution or arbitration
                    step you want to require first.]</span>
                </p>
            </section>

            <section>
                <h2 id="changes">13. Changes</h2>
                <p>
                    We may update these terms as the service develops. The "last updated" date at the top of
                    this page reflects the current version. For material changes we will give you notice by
                    email or in the app before they take effect. Continuing to use Event Host after that
                    means you accept the updated terms.
                </p>
            </section>

            @include('legal.partials.contact-card')
            @include('legal.partials.siblings')

        </div>
    </div>
</div>

@endsection
