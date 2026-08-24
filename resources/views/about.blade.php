@extends('layouts.site')

@section('title', 'About Us | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/about.css') }}">
@endpush

@section('content')

<!-- HERO -->
<section class="about-hero">
    <div class="about-hero-inner">
        <h1>Building a better way to celebrate together</h1>
        <p>Event Host was born from a simple idea — that every host deserves beautiful, stress-free invitations and every guest deserves a seamless experience, no matter where they are.</p>
        <div class="about-hero-ctas">
            <a href="{{ route('register') }}" class="btn-hero-primary">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Create Your Event
            </a>
            <a href="{{ route('contact') }}" class="btn-hero-secondary">Contact Us →</a>
        </div>
    </div>
</section>

<!-- STATS BAR -->
<div class="about-stats-bar">
    <div class="about-stats-inner">
        <div class="about-stat">
            <div class="about-stat-num">5<span>K+</span></div>
            <div class="about-stat-label">Events Created</div>
        </div>
        <div class="about-stat">
            <div class="about-stat-num">120<span>K+</span></div>
            <div class="about-stat-label">RSVPs Processed</div>
        </div>
        <div class="about-stat">
            <div class="about-stat-num">30<span>+</span></div>
            <div class="about-stat-label">Premium Templates</div>
        </div>
        <div class="about-stat">
            <div class="about-stat-num"><span>#</span>1</div>
            <div class="about-stat-label">in Zambia</div>
        </div>
    </div>
</div>

<!-- OUR STORY -->
<section class="about-story">
    <div class="about-section">
        <div class="about-split">
            <div class="about-split-text">
                <span class="about-label">How we started</span>
                <h2>We've been to one too many poorly organized events</h2>
                <p>Managing a guest list in spreadsheets, sending invitations one by one on WhatsApp, and chasing RSVPs through voice notes — we've all been there. Event Host was created because hosts in Zambia deserved better tools, built for the way we actually celebrate.</p>
                <p>We launched with a single mission: make every event — from an intimate birthday dinner to a 500-person wedding — easier to plan, more beautiful to experience, and simpler to track.</p>
            </div>
            <div class="about-split-img">
                <img
                    src="https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&w=900&h=680&q=80"
                    alt="Guests celebrating at a lively indoor event"
                    width="900" height="680" loading="lazy" decoding="async">
            </div>
        </div>
    </div>
</section>

<!-- TICKETING -->
<section class="about-ticketing">
    <div class="about-section">
        <div class="about-section-header">
            <span class="about-label">New capability</span>
            <h2>Now powering ticketed events too</h2>
            <p>Event Host isn't just invitations anymore. Sell tickets for concerts, conferences, fundraisers and parties — right from the same dashboard you already use.</p>
        </div>
        <div class="about-values-grid">
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(30,71,187,0.12);color:var(--accent)">
                    <i class="fa-solid fa-ticket" aria-hidden="true"></i>
                </div>
                <h3>Ticket Types & Pricing</h3>
                <p>Set up General, VIP or custom tiers, each with its own price, quantity and sales window.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(0,206,201,0.12);color:var(--cyan)">
                    <i class="fa-solid fa-credit-card" aria-hidden="true"></i>
                </div>
                <h3>Secure Checkout</h3>
                <p>Buyers pay by MTN Money, Airtel Money or card through EventHost Payments — no account needed.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(224,14,79,0.12);color:var(--pink)">
                    <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
                </div>
                <h3>Instant QR Tickets</h3>
                <p>Every paid order issues a QR ticket by email the moment payment clears — nothing to print or design.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(72,199,142,0.12);color:#27ae60">
                    <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                </div>
                <h3>Door Check-In</h3>
                <p>Scan tickets from any phone at the entrance and watch attendance update live.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(243,156,18,0.12);color:var(--orange)">
                    <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                </div>
                <h3>Revenue Dashboard</h3>
                <p>Track sales, orders and payouts for every ticketed event from one reconciliation dashboard.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(79,62,200,0.1);color:var(--purple-mid)">
                    <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                </div>
                <h3>You Set the Terms</h3>
                <p>Choose whether our commission is absorbed by you or passed on to buyers.</p>
            </div>
        </div>
    </div>
</section>

<!-- MISSION & VALUES -->
<section class="about-values-bg">
    <div class="about-section">
        <div class="about-section-header">
            <span class="about-label">What drives us</span>
            <h2>Our mission and values</h2>
            <p>Every decision we make comes back to the people hosting and attending the events we power.</p>
        </div>
        <div class="about-values-grid">
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(224,14,79,0.12);color:var(--pink)">
                    <i class="fa-solid fa-heart" aria-hidden="true"></i>
                </div>
                <h3>Host-First Design</h3>
                <p>Every feature starts with one question: does this make the host's life easier? We obsess over the details so you don't have to — from the first invitation to the final RSVP.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(30,71,187,0.12);color:var(--accent)">
                    <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                </div>
                <h3>Radical Simplicity</h3>
                <p>We believe powerful tools should be simple to use. Whether you're a first-time host or a seasoned event planner, Event Host guides you from draft to delivery in minutes.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(0,206,201,0.12);color:var(--cyan)">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                </div>
                <h3>Privacy & Trust</h3>
                <p>Your guest data is yours — always. We never sell personal information or share your guest list. Security and privacy are baked into everything we build.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(72,199,142,0.12);color:#27ae60">
                    <i class="fa-solid fa-globe" aria-hidden="true"></i>
                </div>
                <h3>Local Roots, Global Reach</h3>
                <p>Built in Zambia for Zambian celebrations, but designed to work beautifully anywhere in the world. We understand local context without limiting your ambition.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(243,156,18,0.12);color:var(--orange)">
                    <i class="fa-solid fa-palette" aria-hidden="true"></i>
                </div>
                <h3>Beauty in Every Detail</h3>
                <p>Great design isn't a luxury — it sets the tone for the event itself. We pour craftsmanship into every template, every button, and every invitation link your guests open.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon" style="background:rgba(79,62,200,0.1);color:var(--purple-mid)">
                    <i class="fa-solid fa-comments" aria-hidden="true"></i>
                </div>
                <h3>Always Improving</h3>
                <p>We ship updates constantly based on what hosts actually need. Your feedback shapes the product — and we're only getting started.</p>
            </div>
        </div>
    </div>
</section>

<!-- BUILT FOR ZAMBIA -->
<section class="about-zambia">
    <div class="about-section">
        <div class="about-zambia-grid">
            <div class="about-zambia-img">
                <img
                    src="https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?auto=format&fit=crop&w=900&h=680&q=80"
                    alt="Elegant event setup with flowers and table settings"
                    width="900" height="680" loading="lazy" decoding="async">
            </div>
            <div class="about-split-text">
                <span class="about-label">Built for Zambia</span>
                <h2>Designed for the way Zambians celebrate</h2>
                <p>We know how Zambians host — from weddings and Kitchen Parties to graduations and corporate launches. Event Host integrates the payment methods guests trust and the platforms they already use every day.</p>
                <div class="about-zambia-points">
                    <div class="about-zambia-point">
                        <div class="about-zp-icon" style="background:rgba(30,71,187,0.12);color:var(--accent)">
                            <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                        </div>
                        <div class="about-zp-text">
                            <h4>Fast Setup</h4>
                            <p>Live in under 5 minutes</p>
                        </div>
                    </div>
                    <div class="about-zambia-point">
                        <div class="about-zp-icon" style="background:rgba(0,206,201,0.12);color:var(--cyan)">
                            <i class="fa-solid fa-credit-card" aria-hidden="true"></i>
                        </div>
                        <div class="about-zp-text">
                            <h4>Local Payments</h4>
                            <p>MTN MoMo, Airtel Money &amp; cards</p>
                        </div>
                    </div>
                    <div class="about-zambia-point">
                        <div class="about-zp-icon" style="background:rgba(72,199,142,0.12);color:#27ae60">
                            <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                        </div>
                        <div class="about-zp-text">
                            <h4>WhatsApp RSVP</h4>
                            <p>No app needed for guests</p>
                        </div>
                    </div>
                    <div class="about-zambia-point">
                        <div class="about-zp-icon" style="background:rgba(243,156,18,0.12);color:var(--orange)">
                            <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                        </div>
                        <div class="about-zp-text">
                            <h4>Mobile-First</h4>
                            <p>Perfect on any device</p>
                        </div>
                    </div>
                    <div class="about-zambia-point">
                        <div class="about-zp-icon" style="background:rgba(224,14,79,0.1);color:var(--pink)">
                            <i class="fa-solid fa-palette" aria-hidden="true"></i>
                        </div>
                        <div class="about-zp-text">
                            <h4>Elegant Designs</h4>
                            <p>{{ $activeTemplateCount }} premium templates</p>
                        </div>
                    </div>
                    <div class="about-zambia-point">
                        <div class="about-zp-icon" style="background:rgba(79,62,200,0.1);color:var(--purple-mid)">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        </div>
                        <div class="about-zp-text">
                            <h4>Private &amp; Secure</h4>
                            <p>Your guest data stays yours</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FINAL CTA -->
<section class="about-cta">
    <div class="about-cta-inner">
        <h2>Ready to host your next<br>unforgettable event?</h2>
        <p>Join thousands of hosts across Zambia who trust Event Host to make their events shine. Sign up free and publish your first event in minutes.</p>
        <div class="about-cta-actions">
            <a href="{{ route('register') }}" class="btn-hero-primary" style="font-size:16px;padding:16px 36px">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Create Your Event
            </a>
            <a href="{{ url('/#pricing') }}" class="btn-hero-secondary" style="font-size:16px;padding:16px 36px">
                See Pricing →
            </a>
        </div>
    </div>
</section>

@endsection
