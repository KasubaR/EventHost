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
        <div class="legal-updated">Last updated: {{ now()->format('F j, Y') }}</div>
    </div>
</section>

<div class="legal-main">
    <div class="legal-grid">

        <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
                <li><a href="#agreement">Agreement and our role</a></li>
                <li><a href="#accounts">Accounts</a></li>
                <li><a href="#credits">Event credits</a></li>
                <li><a href="#credit-refunds">Event credit refunds</a></li>
                <li><a href="#your-content">Your content</a></li>
                <li><a href="#guest-lists">Guest and attendee information</a></li>
                <li><a href="#acceptable-use">Acceptable use</a></li>
                <li><a href="#reviews">Reviews</a></li>
                <li><a href="#availability">Service availability and support</a></li>
                <li><a href="#ticket-sales">Ticket sales and organiser responsibilities</a></li>
                <li><a href="#commission">Commission and payment charges</a></li>
                <li><a href="#payouts">Payouts</a></li>
                <li><a href="#ticket-refunds">Ticket refunds</a></li>
                <li><a href="#chargebacks">Chargebacks and reconciliation</a></li>
                <li><a href="#suspension">Suspension and account closure</a></li>
                <li><a href="#liability">Liability</a></li>
                <li><a href="#law">Complaints and governing law</a></li>
                <li><a href="#changes">Changes to these terms</a></li>
                <li><a href="#contact">Contact</a></li>
                <li><a href="#refund-scenarios">Refund scenarios</a></li>
            </ol>
        </nav>

        <div class="legal-body">

            <section>
                <h2 id="agreement">1. Agreement and our role</h2>
                <p>These terms govern your use of Event Host, operated by <strong><a href="https://kinpinarts.com/" target="_blank" rel="noopener noreferrer">Kinpin Arts Media</a></strong> ("Event Host", "we", "us").</p>
                <p>By creating an account, publishing an event or purchasing through Event Host, you agree to these terms and the applicable terms disclosed before your transaction. Our <a href="{{ route('legal.privacy') }}">Privacy Policy</a> and <a href="{{ route('legal.cookies') }}">Cookie Policy</a> explain how we handle personal information and cookies.</p>
                <p>If you act for an organisation, you confirm that you are authorised to bind it.</p>
                <p>Event Host provides event-management, invitation and ticketing services. The organiser is responsible for delivering the advertised event, including its safety, permissions, venue and admission arrangements. Event Host remains responsible for its own services and obligations under applicable law.</p>
            </section>

            <section>
                <h2 id="accounts">2. Accounts</h2>
                <p>Account holders must be at least 18 years old and provide accurate information. Email verification is required before accessing the organiser dashboard.</p>
                <p>Keep your login details secure and notify us promptly of suspected unauthorised access. Do not share accounts or create an account for another person without permission.</p>
                <p>Additional identity, business or payment verification may be required before ticket sales or payouts.</p>
            </section>

            <section>
                <h2 id="credits">3. Event credits</h2>
                <p>Publishing an event uses one event credit. Creating a draft is free.</p>
                <p>Credits are purchased through the payment methods offered at checkout, in the displayed currency. Prices and applicable charges are shown before payment.</p>
                <p>Credits do not expire, have no cash value and cannot be transferred. Price changes do not affect credits already purchased. Promotional or support credits cannot be exchanged for cash.</p>
                <p>Deleting an account forfeits unused credits, subject to applicable law.</p>
            </section>

            <section>
                <h2 id="credit-refunds">4. Event credit refunds</h2>
                <p>This section applies only to event credit purchases, not event tickets.</p>
                <p>Event-credit purchases are generally final, whether used or unused, subject to rights under applicable law. Unused credits remain available for future events while the account remains open.</p>
                <p>If a payment is duplicated, charged incorrectly or completed without the purchased credits being supplied, contact us. We will investigate and correct the credit balance or arrange an appropriate refund.</p>
            </section>

            <section>
                <h2 id="your-content">5. Your content</h2>
                <p>You retain ownership of your content. You grant Event Host a worldwide, non-exclusive, royalty-free licence to use it only as necessary to provide and operate the service.</p>
                <p>You must have permission to use uploaded text, images, audio and other materials.</p>
                <p id="music"><strong>Music and other audio.</strong> Buying or streaming a song does not necessarily grant permission to upload or distribute it. Upload music only if you made it or hold a licence that lets you copy it and play it to your guests on the internet.</p>
                <p>You are responsible for infringement caused by content you supply and, to the extent permitted by law, reasonable resulting claims and costs attributable to your breach.</p>
                <p>We may remove infringing content and suspend repeat offenders. Copyright concerns may be reported through our copyright-reporting channel or support: use the &ldquo;Report this music&rdquo; link at the foot of an invitation, or the <a href="{{ route('contact') }}">contact page</a>.</p>
            </section>

            <section>
                <h2 id="guest-lists">6. Guest and attendee information</h2>
                <p>Organisers must have a lawful basis for collecting and using guest or attendee information and must explain its intended use.</p>
                <p>Do not upload lists for unsolicited messages. Invitation recipients should reasonably expect to hear from you.</p>
                <p>We handle information according to our Privacy Policy and, where applicable, the organiser's lawful instructions. We also process information necessary for payments, security, support and legal obligations.</p>
                <p>Organisers must cooperate with requests concerning attendee information. Guest lists and ticket records must not be reused for unrelated marketing without an appropriate lawful basis.</p>
            </section>

            <section>
                <h2 id="acceptable-use">7. Acceptable use</h2>
                <p>You must not:</p>
                <ul>
                    <li>Publish unlawful, fraudulent, threatening, hateful, defamatory or infringing material.</li>
                    <li>Promote fictitious events or misrepresent an event or your authority.</li>
                    <li>Send spam, distribute malware or impersonate others.</li>
                    <li>Bypass security, interfere with the service or collect data without authorisation.</li>
                    <li>Resell or white-label Event Host without written agreement.</li>
                </ul>
                <p>We may investigate suspected misuse and take proportionate action.</p>
            </section>

            <section>
                <h2 id="reviews">8. Reviews</h2>
                <p>Reviews must reflect genuine experience and must not be misleading, abusive or fabricated.</p>
                <p>Reviews may be moderated. Editing a published review may return it to moderation. We may decline or remove reviews that breach these terms.</p>
            </section>

            <section>
                <h2 id="availability">9. Service availability and support</h2>
                <p>We aim to provide a reliable service but cannot guarantee uninterrupted availability or delivery of every email. Maintenance and service changes may occur.</p>
                <p>Organisers should independently confirm essential communications and maintain suitable admission arrangements.</p>
                <p>Support is available from 08:00 to 20:00 Central Africa Time at <a href="mailto:info@eventhostzm.com">info@eventhostzm.com</a>. We aim to respond within one business day; this is a service aim rather than a guaranteed resolution time.</p>
            </section>

            <section>
                <h2 id="ticket-sales">10. Ticket sales and organiser responsibilities</h2>
                <p>Organisers must accurately disclose the event date, venue, ticket price, inclusions, restrictions and refund conditions before purchase.</p>
                <p>All buyer charges must be visible before payment. Event-specific conditions cannot remove rights under these terms or applicable law.</p>
                <p>Organisers must honour valid tickets and promptly notify Event Host and affected buyers of cancellations, postponements or material changes.</p>
                <p>A confirmed purchase cannot be cancelled solely because the organiser later wishes to charge a higher price.</p>
            </section>

            <section>
                <h2 id="commission">11. Commission and payment charges</h2>
                <p>Commission is agreed with each organiser in writing before ticket sales begin.</p>
                <p>The agreement must identify:</p>
                <ul>
                    <li>The commission rate or amount and how it is calculated.</li>
                    <li>Whether payment-processing charges and applicable taxes are included.</li>
                    <li>Any separately payable charges.</li>
                    <li>Whether the organiser absorbs charges or passes permitted charges to buyers.</li>
                </ul>
                <p>Changes do not apply retrospectively to completed sales unless lawfully agreed by the affected parties.</p>
            </section>

            <section>
                <h2 id="payouts">12. Payouts</h2>
                <h3>Standard payout</h3>
                <p>Unless expressly agreed otherwise in writing, Event Host will initiate payment within 5 (five) business days after the event concludes, subject to cleared funds, verified payment details and any justified hold.</p>
                <p>Net proceeds are cleared ticket receipts less agreed commission, disclosed charges, refunds, chargebacks and any justified reserve. Deductions must be identifiable in the payout statement.</p>
                <p>The initiation date is different from the date funds arrive. Banks and payment providers may require additional processing time.</p>
                <h3>Early payouts</h3>
                <p>Early payouts are not allowed unless Event Host and the organiser explicitly agree in writing.</p>
                <p>The agreement must specify the amount, timing, conditions, any reserve and responsibility for refunds or chargebacks. Approval for one event does not extend to another.</p>
                <p>An early payout does not remove the organiser's obligation to deliver the event or fund refunds for which they are responsible.</p>
                <h3>Holds and reserves</h3>
                <p>Event Host may withhold a reasonable amount where necessary to address suspected fraud, disputed payments, cancellation risk, unresolved refunds, payment-provider restrictions or legal requirements.</p>
                <p>Unless prohibited by law, we will explain the reason, amount affected, information required and next review date. Holds will be reviewed and released promptly when their grounds no longer apply. Unaffected funds will be paid where reasonably separable.</p>
            </section>

            <section>
                <h2 id="ticket-refunds">13. Ticket refunds</h2>
                <p>This section applies to ticket purchases. It is separate from the event-credit policy.</p>
                <h3>When refunds are allowed</h3>
                <p><strong>Cancelled events:</strong> Buyers are entitled to a refund for an event that is cancelled and not delivered.</p>
                <p><strong>Postponed or rescheduled events:</strong> Buyers may accept the replacement date or request a refund if they cannot or do not wish to attend it.</p>
                <p><strong>Material changes:</strong> Buyers may request a refund when a substantial change means the event no longer substantially matches what they purchased. Examples include moving it to another city or removing the principal advertised attraction. Minor timetable or supporting-line-up changes do not automatically qualify.</p>
                <p><strong>Payment or ticketing faults:</strong> Verified duplicate charges, incorrect charges and payments for which a valid ticket was not supplied will be corrected or refunded. A successfully supplied replacement ticket may resolve a delivery fault if the buyer can still use it.</p>
                <p><strong>Wrongful refusal of entry:</strong> A buyer with a valid ticket who complies with the published admission conditions may request a refund if entry is refused because of an organiser error, overselling or failure to provide the purchased admission.</p>
                <p><strong>Partial delivery or interruption:</strong> Where an event is abandoned or a significant purchased element is not delivered, the appropriate full or partial refund will depend on what was supplied and applicable law.</p>
                <p>Other refunds may be offered under the organiser's disclosed policy or agreed voluntarily.</p>
                <h3>When refunds are not normally required</h3>
                <p>A refund is not normally due solely because a buyer changes their mind, does not attend, arrives late or fails to meet clearly disclosed admission conditions.</p>
                <p>Subjective dissatisfaction does not automatically qualify. However, complaints about misleading descriptions, substantial non-delivery or legal rights will still be considered.</p>
                <h3>Refund amounts and charges</h3>
                <p>For cancellation or a qualifying material change affecting the whole purchase, the proposed policy is to refund the ticket price and mandatory charges paid for the affected ticket.</p>
                <p>No refund-processing fee will be deducted from a buyer's refund for a verified platform fault or duplicate charge.</p>
                <p>Any costs allocated between Event Host and the organiser must be agreed separately and cannot reduce a refund required under these terms or applicable law. These terms do not establish a fixed refund-processing percentage.</p>
                <h3>Requests and processing</h3>
                <p>Contact <a href="mailto:info@eventhostzm.com">info@eventhostzm.com</a> with the order reference, event and reason for the request. Submit requests promptly so that the relevant records can be checked.</p>
                <p>We will acknowledge the request, explain any evidence needed and provide the expected processing timeframe. Approved refunds will normally return through the original payment method, subject to provider requirements.</p>
                <p>Organisers must cooperate and fund refunds for which they are responsible, including returning previously paid proceeds where necessary. A mandatory refund does not become discretionary merely because an organiser has not authorised it.</p>
            </section>

            <section>
                <h2 id="chargebacks">14. Chargebacks and reconciliation</h2>
                <p>Organisers are responsible for documented chargebacks and associated charges attributable to their event or obligations, subject to applicable law and the agreed payment arrangements.</p>
                <p>Event Host may deduct properly due amounts from organiser balances or request repayment. We will explain deductions and allow relevant supporting evidence to be submitted.</p>
                <p>Buyers retain any lawful rights to dispute payments through their payment provider.</p>
            </section>

            <section>
                <h2 id="suspension">15. Suspension and account closure</h2>
                <p>We may restrict or suspend an account for material breaches, suspected fraud or legal requirements. Where reasonable, we will explain the issue and allow an opportunity to resolve it.</p>
                <p>Account deletion removes events and guest information subject to lawful record retention. Records of payments are the exception: we keep those. Accounts with unresolved sales, refunds or other financial obligations must complete a wind-down process through support, and we cannot delete an account while one of its events has ticket sales, refunds or contribution payments on record.</p>
                <p>Suspension or closure does not automatically forfeit ticket proceeds. Outstanding balances will be reconciled and paid after valid deductions and justified holds.</p>
            </section>

            <section>
                <h2 id="liability">16. Liability</h2>
                <p>Nothing in these terms excludes liability or consumer rights that cannot lawfully be excluded, including liability for fraud or death or personal injury caused by negligence.</p>
                <p>To the extent permitted by law, Event Host excludes liability for indirect or consequential losses. For other service claims, liability is limited to fees paid to Event Host during the preceding 12 months, or the price of one event credit if no fees were paid.</p>
                <p>This limit does not reduce our obligation to remit organiser funds properly due or provide refunds owed under these terms or applicable law.</p>
                <p>Organisers remain responsible for their events. This does not excuse Event Host from responsibility for its own acts or omissions.</p>
            </section>

            <section>
                <h2 id="law">17. Complaints and governing law</h2>
                <p>Please contact support first with details of a complaint so we can attempt to resolve it.</p>
                <p>These terms are governed by the laws of Zambia. Disputes may be brought before the competent courts of Zambia, subject to any mandatory rights concerning jurisdiction.</p>
                <p>Nothing prevents a person from contacting a regulator or exercising a statutory remedy.</p>
            </section>

            <section>
                <h2 id="changes">18. Changes to these terms</h2>
                <p>We will give reasonable advance notice of material changes by email or through the platform and state their effective date.</p>
                <p>Changes will not retrospectively reduce rights attached to completed purchases or alter an agreed organiser commission without lawful agreement.</p>
            </section>

            <section>
                <h2 id="contact">19. Contact</h2>
                <p><strong>Kinpin Arts Media - Event Host</strong><br>Email: <a href="mailto:info@eventhostzm.com">info@eventhostzm.com</a><br>Phone: +260 965 023 606 | +260 971 654 278<br>Address: Unit C11, Chamba Valley Shopping Plaza, Maposa Road, Lusaka, Zambia</p>
            </section>

            <section>
                <h2 id="refund-scenarios">Refund scenarios</h2>
                <p>These examples explain the proposed policy in section 13. Outcomes remain subject to the facts and applicable law.</p>
                <div class="legal-table-wrap">
                    <table class="legal-table">
                        <thead><tr><th scope="col">Scenario</th><th scope="col">Proposed outcome</th></tr></thead>
                        <tbody>
                            <tr><td>Organiser cancels the concert</td><td>Full refund of affected tickets and mandatory buyer charges.</td></tr>
                            <tr><td>Event moves to the following month</td><td>Buyer can accept the new date or request a refund.</td></tr>
                            <tr><td>Event moves to another city</td><td>Qualifying material change; refund available.</td></tr>
                            <tr><td>Supporting performer changes</td><td>No automatic refund if the advertised event substantially proceeds. Assess the advertised promise and circumstances.</td></tr>
                            <tr><td>Buyer is charged twice</td><td>Refund the duplicate charge without a refund-processing fee.</td></tr>
                            <tr><td>Ticket email is missing</td><td>Resend a valid ticket if it can still be used. A missing email alone does not require refunding a usable purchase.</td></tr>
                            <tr><td>Valid ticket holder refused entry because the venue is oversold.</td><td>Refund available.</td></tr>
                            <tr><td>Buyer decides not to attend</td><td>Normally no refund, unless the organiser policy allows it.</td></tr>
                            <tr><td>Buyer breaches disclosed age or conduct rules</td><td>Normally no refund, subject to the facts and applicable law.</td></tr>
                            <tr><td>Event stops halfway through</td><td>Assess full or partial refund based on delivery and legal obligations.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            @include('legal.partials.siblings')

        </div>
    </div>
</div>

@endsection
