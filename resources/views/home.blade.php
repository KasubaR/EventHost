@extends('layouts.site')

@section('title', 'Event Host — Create Beautiful Digital Invitations')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
@endpush

@section('content')

<!-- HERO -->
<section id="hero">
  <div class="hero-inner">
    <div class="hero-left">
      <h1 class="hero-headline">
        Create beautiful digital invitations in minutes
      </h1>
      <p class="hero-sub">Design stunning invitations, manage RSVPs, and track your guests — all from one elegant platform. Works on WhatsApp too.</p>
      <div class="hero-ctas">
        <button class="btn-hero-primary">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
          Create Free Event
        </button>
        <a href="{{ route('templates.index') }}" class="btn-hero-secondary">
          Browse Templates →
        </a>
      </div>
    </div>
    <div class="hero-right">
      <div class="phone-notif2">
        <div class="counter-num">247</div>
        <div class="counter-sub"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>RSVPs confirmed</span></div>
      </div>
      <div class="phone-frame">
        <div class="phone-notch"></div>
        <div class="phone-screen">
          <div class="phone-inv-header">
            <img class="phone-inv-header-bg" src="https://images.unsplash.com/photo-1558636508-e0db3814bd5d?auto=format&fit=crop&w=520&h=360&q=80" alt="" width="260" height="160" loading="lazy" decoding="async">
            <div class="phone-inv-header-inner">
              <div class="inv-header-icon"><i class="fa-solid fa-cake-candles" aria-hidden="true"></i></div>
              <h3>Sarah's Birthday Bash</h3>
              <p>You're officially invited!</p>
            </div>
          </div>
          <div class="phone-inv-body">
            <div class="phone-inv-detail">
              <span class="icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
              <span>Saturday, June 14 · 7:00 PM</span>
            </div>
            <div class="phone-inv-detail">
              <span class="icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
              <span>Kabanje Ballroom, Lusaka</span>
            </div>
            <div class="phone-inv-detail">
              <span class="icon"><i class="fa-solid fa-shirt" aria-hidden="true"></i></span>
              <span>Dress Code: Cocktail Attire</span>
            </div>
            <div class="phone-inv-rsvp">
              <button type="button" class="rsvp-btn rsvp-yes"><i class="fa-solid fa-check" aria-hidden="true"></i> Attending</button>
              <button type="button" class="rsvp-btn rsvp-no"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Decline</button>
            </div>
          </div>
        </div>
      </div>
      <div class="phone-notif">
        <div class="notif-head">
          <i class="fa-solid fa-envelope" aria-hidden="true"></i>
          <span class="notif-title">New RSVP!</span>
        </div>
        <div class="notif-sub">Michael confirmed attendance just now</div>
      </div>
    </div>
  </div>
</section>

<!-- EVENT TYPES -->
<section id="event-types">
  <div class="section">
    <div class="event-types-header">
      <p class="event-types-note">
        @auth
          <a href="{{ url('/dashboard') }}">Go to your dashboard</a> to create and manage your event invitations.
        @else
          <a href="{{ route('login') }}">Sign in</a> or <a href="{{ route('register') }}">Sign up</a> to create and manage your event invitations.
        @endauth
      </p>
    </div>
    <div class="event-types-grid">
      <article class="event-type-item">
        <div class="event-type-card weddings" aria-hidden="true"></div>
        <h3>Weddings</h3>
      </article>
      <article class="event-type-item">
        <div class="event-type-card birthdays" aria-hidden="true"></div>
        <h3>Birthdays</h3>
      </article>
      <article class="event-type-item">
        <div class="event-type-card graduation" aria-hidden="true"></div>
        <h3>Graduation</h3>
      </article>
      <article class="event-type-item">
        <div class="event-type-card corporate" aria-hidden="true"></div>
        <h3>Corporate</h3>
      </article>
      <article class="event-type-item">
        <div class="event-type-card baby-shower" aria-hidden="true"></div>
        <h3>Baby Shower</h3>
      </article>
      <article class="event-type-item">
        <div class="event-type-card memorial" aria-hidden="true"></div>
        <h3>Memorial</h3>
      </article>
      <article class="event-type-item">
        <div class="event-type-card church" aria-hidden="true"></div>
        <h3>Church</h3>
      </article>
    </div>
  </div>
</section>

<!-- FEATURES -->
<div id="features">
  <div class="section">
    <div class="section-header">
      <h2>Everything you need to host with confidence</h2>
      <p>Powerful features designed for hosts who care about every detail.</p>
    </div>
    <div class="features-banner">
      <img src="https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?auto=format&fit=crop&w=1400&h=560&q=80" alt="Elegant event table setup with florals and glassware" width="1400" height="560" loading="lazy" decoding="async">
    </div>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feat-icon" style="background:rgba(108,92,231,0.12)"><i class="fa-solid fa-palette" aria-hidden="true"></i></div>
        <h3>Beautiful Templates</h3>
        <p>Choose from 100+ professionally designed invitation templates for every occasion — weddings, birthdays, corporate events and more.</p>
      </div>
      <div class="feature-card">
        <div class="feat-icon" style="background:rgba(0,206,201,0.12)"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></div>
        <h3>RSVP Tracking</h3>
        <p>See who's coming in real time. Get instant notifications, track responses, and export your guest list with a single click.</p>
      </div>
      <div class="feature-card">
        <div class="feat-icon" style="background:rgba(72,199,142,0.12)"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></div>
        <h3>WhatsApp Sharing</h3>
        <p>Share invitations directly via WhatsApp. Guests can RSVP without downloading any app — just a tap and they're in.</p>
      </div>
      <div class="feature-card">
        <div class="feat-icon" style="background:rgba(243,156,18,0.12)"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
        <h3>Guest Management</h3>
        <p>Manage seating, meal preferences, +1s and custom fields. Keep all your guest data organized in one place.</p>
      </div>
      <div class="feature-card">
        <div class="feat-icon" style="background:rgba(108,92,231,0.12)"><i class="fa-solid fa-chart-column" aria-hidden="true"></i></div>
        <h3>Event Analytics</h3>
        <p>Understand engagement with open rates, response timelines, and geographic insights for your events.</p>
      </div>
      <div class="feature-card">
        <div class="feat-icon" style="background:rgba(79,62,200,0.1)"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i></div>
        <h3>Mobile Optimized</h3>
        <p>Every invitation looks stunning on any device. Your guests get a flawless experience whether on iPhone, Android, or desktop.</p>
      </div>
    </div>
  </div>
</div>

<!-- TEMPLATES SHOWCASE -->
<div id="templates">
  <div class="section">
    <div class="section-row">
      <h2>Invitation Templates</h2>
      <a href="{{ route('templates.index') }}" class="see-all">See all templates →</a>
    </div>
    <div class="filter-tabs">
      <button type="button" class="filter-tab active">All</button>
      <button type="button" class="filter-tab"><i class="fa-solid fa-ring" aria-hidden="true"></i> Wedding</button>
      <button type="button" class="filter-tab"><i class="fa-solid fa-cake-candles" aria-hidden="true"></i> Birthday</button>
      <button type="button" class="filter-tab"><i class="fa-solid fa-building" aria-hidden="true"></i> Corporate</button>
      <button type="button" class="filter-tab"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> Graduation</button>
      <button type="button" class="filter-tab"><i class="fa-solid fa-champagne-glasses" aria-hidden="true"></i> Party</button>
    </div>
    <div class="templates-grid">
      <div class="template-card">
        <div class="tmpl-img">
          <img src="https://images.unsplash.com/photo-1519741497674-611481863552?auto=format&fit=crop&w=600&h=800&q=80" alt="Wedding rings and flowers invitation mood" width="600" height="800" loading="lazy" decoding="async">
        </div>
        <div class="tmpl-info">
          <h4>Golden Garden Wedding</h4>
          <p>Elegant · Classic</p>
        </div>
        <div class="tmpl-overlay">
          <button style="background:var(--accent);color:#fff;border:none;">Preview</button>
          <button style="background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2);">Use Template</button>
        </div>
      </div>
      <div class="template-card">
        <div class="tmpl-img">
          <img src="https://images.unsplash.com/photo-1527529482837-4698179dc6ce?auto=format&fit=crop&w=600&h=800&q=80" alt="Colorful birthday balloons and celebration" width="600" height="800" loading="lazy" decoding="async">
        </div>
        <div class="tmpl-info">
          <h4>Vibrant Birthday Bash</h4>
          <p>Fun · Colorful</p>
        </div>
        <div class="tmpl-overlay">
          <button style="background:var(--accent);color:#fff;border:none;">Preview</button>
          <button style="background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2);">Use Template</button>
        </div>
      </div>
      <div class="template-card">
        <div class="tmpl-img">
          <img src="https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=600&h=800&q=80" alt="Modern corporate office building interior" width="600" height="800" loading="lazy" decoding="async">
        </div>
        <div class="tmpl-info">
          <h4>Executive Corporate</h4>
          <p>Professional · Clean</p>
        </div>
        <div class="tmpl-overlay">
          <button style="background:var(--accent);color:#fff;border:none;">Preview</button>
          <button style="background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2);">Use Template</button>
        </div>
      </div>
      <div class="template-card">
        <div class="tmpl-img">
          <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=600&h=800&q=80" alt="Graduates throwing caps in celebration" width="600" height="800" loading="lazy" decoding="async">
        </div>
        <div class="tmpl-info">
          <h4>Graduation Gala</h4>
          <p>Celebratory · Formal</p>
        </div>
        <div class="tmpl-overlay">
          <button style="background:var(--accent);color:#fff;border:none;">Preview</button>
          <button style="background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2);">Use Template</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- HOW IT WORKS -->
<div id="how">
  <div class="section">
    <div class="section-header" style="text-align:center">
      <h2>Up and running in 3 simple steps</h2>
      <p style="margin:0 auto">No design experience needed. Just pick, customize, and share.</p>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-photo">
          <img src="https://images.unsplash.com/photo-1586281380349-632531db7ed4?auto=format&fit=crop&w=440&h=330&q=80" alt="Designer selecting colors and stationery" width="220" height="165" loading="lazy" decoding="async">
        </div>
        <div class="step-num">1</div>
        <div class="step-icon"><i class="fa-solid fa-palette" aria-hidden="true"></i></div>
        <h3>Choose a Template</h3>
        <p>Browse our curated library of stunning invitation designs for every event type and aesthetic.</p>
      </div>
      <div class="step">
        <div class="step-photo">
          <img src="https://images.unsplash.com/photo-1499951360447-b19be8fe80f5?auto=format&fit=crop&w=440&h=330&q=80" alt="Working on creative layout at a desk" width="220" height="165" loading="lazy" decoding="async">
        </div>
        <div class="step-num">2</div>
        <div class="step-icon"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></div>
        <h3>Customize It</h3>
        <p>Add your event details, photos, colors, and personal touches with our intuitive editor — in minutes.</p>
      </div>
      <div class="step">
        <div class="step-photo">
          <img src="https://images.unsplash.com/photo-1526498460520-4c246339dccb?auto=format&fit=crop&w=440&h=330&q=80" alt="Sharing from a laptop and phone" width="220" height="165" loading="lazy" decoding="async">
        </div>
        <div class="step-num">3</div>
        <div class="step-icon"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></div>
        <h3>Share & Track RSVPs</h3>
        <p>Send via WhatsApp, email, or a link. Watch confirmations roll in and manage your guest list live.</p>
      </div>
    </div>
  </div>
</div>

<!-- RSVP DASHBOARD PREVIEW -->
<div id="dashboard">
  <div class="section">
    <div class="dash-grid">
      <div class="dash-left">
        <div class="hero-badge" style="margin-bottom:20px"><span class="dot"></span> Real-time tracking</div>
        <h2>Track every RSVP as it happens</h2>
        <p>No more chasing guests. Our dashboard gives you a live view of who's coming, who's not, and who hasn't responded — so you can plan with confidence.</p>
        <div class="dash-points">
          <div class="dash-point">
            <div class="check"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <div class="txt"><h4>Instant notifications</h4><p>Get alerted the moment someone RSVPs</p></div>
          </div>
          <div class="dash-point">
            <div class="check"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <div class="txt"><h4>Guest list export</h4><p>Download as CSV or PDF anytime</p></div>
          </div>
          <div class="dash-point">
            <div class="check"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <div class="txt"><h4>Reminder automation</h4><p>Auto-send reminders to non-responders</p></div>
          </div>
        </div>
      </div>
      <div class="dash-mockup">
        <div class="dash-header">
          <h4>Sarah's Birthday · Jun 14</h4>
          <span class="dash-badge"><i class="fa-solid fa-circle dash-live-dot" aria-hidden="true"></i> Live</span>
        </div>
        <div class="rsvp-chart">
          <div class="bar" style="height:55%;background:linear-gradient(to top,#1a1a6e,#6c5ce7)"></div>
          <div class="bar" style="height:70%;background:linear-gradient(to top,#1a1a6e,#6c5ce7)"></div>
          <div class="bar" style="height:45%;background:linear-gradient(to top,#1a1a6e,#6c5ce7)"></div>
          <div class="bar" style="height:90%;background:linear-gradient(to top,#4f3ec8,#00cec9)"></div>
          <div class="bar" style="height:75%;background:linear-gradient(to top,#4f3ec8,#00cec9)"></div>
          <div class="bar" style="height:60%;background:linear-gradient(to top,#1a1a6e,#6c5ce7)"></div>
          <div class="bar" style="height:85%;background:linear-gradient(to top,#4f3ec8,#00cec9)"></div>
        </div>
        <div class="rsvp-stats">
          <div class="rsvp-stat-box">
            <div class="n" style="color:#48c78e">84</div>
            <div class="l">Attending</div>
          </div>
          <div class="rsvp-stat-box">
            <div class="n" style="color:#e84393">12</div>
            <div class="l">Declined</div>
          </div>
          <div class="rsvp-stat-box">
            <div class="n" style="color:#f39c12">31</div>
            <div class="l">Awaiting</div>
          </div>
        </div>
        <div class="guest-list">
          <div class="guest-row">
            <div class="guest-av"><img src="https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=56&h=56&q=80" alt="" width="28" height="28" loading="lazy" decoding="async"></div>
            <span class="guest-name">Mutinta Mulenga</span>
            <span class="guest-status st-yes">Confirmed</span>
          </div>
          <div class="guest-row">
            <div class="guest-av"><img src="https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?auto=format&fit=crop&w=56&h=56&q=80" alt="" width="28" height="28" loading="lazy" decoding="async"></div>
            <span class="guest-name">Patrick Lungu</span>
            <span class="guest-status st-maybe">Maybe</span>
          </div>
          <div class="guest-row">
            <div class="guest-av"><img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=56&h=56&q=80" alt="" width="28" height="28" loading="lazy" decoding="async"></div>
            <span class="guest-name">Naomi Mukelabai</span>
            <span class="guest-status st-yes">Confirmed</span>
          </div>
          <div class="guest-row">
            <div class="guest-av"><img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=56&h=56&q=80" alt="" width="28" height="28" loading="lazy" decoding="async"></div>
            <span class="guest-name">Joseph Tembo</span>
            <span class="guest-status st-no">Declined</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- WHY CHOOSE US -->
<div id="why">
  <div class="section">
    <div class="why-grid">
      <div class="why-img-block">
        <img class="why-bg-photo" src="https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&w=960&h=1200&q=80" alt="Guests mingling at a lively indoor celebration" width="480" height="600" loading="lazy" decoding="async">
      </div>
      <div class="why-right">
        <div class="hero-badge" style="margin-bottom:18px"><span class="dot"></span> Why Event Host</div>
        <h2>Built for Zambian hosts, designed for the world</h2>
        <p>We know how Zambians celebrate—from weddings and Kitchen Parties to graduations and corporate launches. Event Host brings MTN, Airtel &amp; Zamtel mobile money together with cards and banking paths guests trust, plus WhatsApp-first sharing.</p>
        <div class="why-points">
          <div class="why-point">
            <div class="wp-icon" style="background:rgba(108,92,231,0.12)"><i class="fa-solid fa-bolt" aria-hidden="true"></i></div>
            <div class="wp-text"><h4>Fast Setup</h4><p>Live in under 5 minutes</p></div>
          </div>
          <div class="why-point">
            <div class="wp-icon" style="background:rgba(0,206,201,0.12)"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></div>
            <div class="wp-text"><h4>Local Payments</h4><p>MTN MoMo, Airtel Money, Zamtel Kwacha &amp; cards</p></div>
          </div>
          <div class="why-point">
            <div class="wp-icon" style="background:rgba(72,199,142,0.12)"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></div>
            <div class="wp-text"><h4>WhatsApp RSVP</h4><p>No app needed for guests</p></div>
          </div>
          <div class="why-point">
            <div class="wp-icon" style="background:rgba(243,156,18,0.12)"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i></div>
            <div class="wp-text"><h4>Mobile-First</h4><p>Perfect on any device</p></div>
          </div>
          <div class="why-point">
            <div class="wp-icon" style="background:rgba(108,92,231,0.12)"><i class="fa-solid fa-palette" aria-hidden="true"></i></div>
            <div class="wp-text"><h4>Elegant Designs</h4><p>100+ premium templates</p></div>
          </div>
          <div class="why-point">
            <div class="wp-icon" style="background:rgba(79,62,200,0.1)"><i class="fa-solid fa-lock" aria-hidden="true"></i></div>
            <div class="wp-text"><h4>Private & Secure</h4><p>Your guest data stays yours</p></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- TESTIMONIALS -->
<div id="testimonials">
  <div class="section">
    <div class="section-header">
      <h2>Loved by hosts everywhere</h2>
      <p>Don't just take our word for it — hear from people who've used Event Host for their special moments.</p>
    </div>
    <div class="testi-grid">
      <div class="testi-card">
        <div class="stars" aria-label="5 out of 5 stars">
          <i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i>
        </div>
        <blockquote>"I used Event Host for my wedding and was completely blown away. 200 guests RSVPed with zero confusion. My mother-in-law even figured it out on WhatsApp!"</blockquote>
        <div class="testi-author">
          <div class="testi-av"><img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=88&h=88&q=80" alt="Portrait of Namwali Musonda" width="44" height="44" loading="lazy" decoding="async"></div>
          <div class="testi-info"><h4>Namwali Musonda</h4><span>Wedding · Lusaka</span></div>
        </div>
      </div>
      <div class="testi-card">
        <div class="stars" aria-label="5 out of 5 stars">
          <i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i>
        </div>
        <blockquote>"The dashboard is incredible. I could see live responses coming in, send reminders, and export everything for our caterer. Saved me hours of WhatsApp chasing."</blockquote>
        <div class="testi-author">
          <div class="testi-av"><img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=88&h=88&q=80" alt="Portrait of Chanda Bwalya" width="44" height="44" loading="lazy" decoding="async"></div>
          <div class="testi-info"><h4>Chanda Bwalya</h4><span>Corporate Event · Ndola</span></div>
        </div>
      </div>
      <div class="testi-card">
        <div class="stars" aria-label="5 out of 5 stars">
          <i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i><i class="fa-solid fa-star" aria-hidden="true"></i>
        </div>
        <blockquote>"My daughter's graduation ceremony looked so professional. The template was gorgeous and guests kept asking who designed the invitation. Worth every penny."</blockquote>
        <div class="testi-author">
          <div class="testi-av"><img src="https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=88&h=88&q=80" alt="Portrait of Mutinta Phiri" width="44" height="44" loading="lazy" decoding="async"></div>
          <div class="testi-info"><h4>Mutinta Phiri</h4><span>Graduation · Livingstone</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PRICING -->
<div id="pricing">
  <div class="section">
    <div class="section-header" style="text-align:center">
      <h2>Simple, honest pricing</h2>
      <p style="margin:0 auto">Start with Base. Upgrade when you need more power.</p>
    </div>
    <div class="pricing-grid">
      <div class="price-card">
        <div class="price-plan">Base</div>
        <div class="price-amount"><sup>K</sup>450<span class="period"> / event</span></div>
        <div class="price-desc">Perfect for trying things out</div>
        <ul class="price-features">
          <li>1 active event</li>
          <li>Up to 50 guests</li>
          <li>5 free templates</li>
          <li>Basic RSVP tracking</li>
          <li>WhatsApp sharing</li>
        </ul>
        <button class="btn-price btn-price-outline">Get Started</button>
      </div>
      <div class="price-card popular">
        <span class="popular-badge">Most Popular</span>
        <div class="price-plan">Pro</div>
        <div class="price-amount"><sup>K</sup>750<span class="period"> / event</span></div>
        <div class="price-desc">For serious hosts who want everything</div>
        <ul class="price-features">
          <li>Unlimited events</li>
          <li>Unlimited guests</li>
          <li>100+ premium templates</li>
          <li>Advanced RSVP dashboard</li>
          <li>Custom branding</li>
          <li>Email + WhatsApp reminders</li>
          <li>Analytics & exports</li>
        </ul>
        <button class="btn-price btn-price-fill">Start Pro Trial</button>
      </div>
      <div class="price-card">
        <div class="price-plan">Pro+</div>
        <div class="price-amount"><sup>K</sup>1500<span class="period"> / event</span></div>
        <div class="price-desc">For event planners & agencies</div>
        <ul class="price-features">
          <li>Everything in Pro</li>
          <li>Multiple team members</li>
          <li>White-label invitations</li>
          <li>Priority support</li>
          <li>API access</li>
          <li>Dedicated account manager</li>
        </ul>
        <button class="btn-price btn-price-outline">Contact Sales</button>
      </div>
    </div>
  </div>
</div>

<!-- FAQ -->
<div id="faq">
  <div class="section">
    <div class="section-header" style="text-align:center">
      <h2>Frequently asked questions</h2>
      <p style="margin:0 auto">Got questions? We've got answers.</p>
    </div>
    <div class="faq-grid">
      <div class="faq-item">
        <button type="button" class="faq-q">
          Is Event Host really free to use?
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>Yes! Our Free plan lets you create your first event with up to 50 guests at absolutely no cost. No credit card required. Upgrade to Pro whenever you need more power.</p></div>
      </div>
      <div class="faq-item">
        <button type="button" class="faq-q">
          Can I manage RSVPs from my guests?
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>Absolutely. Your dashboard updates in real time as guests respond. You can see confirmed, declined, and awaiting responses, send reminders, and export the full list.</p></div>
      </div>
      <div class="faq-item">
        <button type="button" class="faq-q">
          Do guests need to create an account to RSVP?
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>No account needed. Guests simply tap the link in their WhatsApp or email, view the invitation, and confirm their attendance in one tap. It's designed to be frictionless.</p></div>
      </div>
      <div class="faq-item">
        <button type="button" class="faq-q">
          Does it work well on mobile phones?
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>Every invitation and the RSVP experience is fully mobile-optimized. Whether your guests are on an old Android or the latest iPhone, everything looks and works beautifully.</p></div>
      </div>
      <div class="faq-item">
        <button type="button" class="faq-q">
          Can I send invitations via WhatsApp?
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>Yes — WhatsApp sharing is built in. You get a shareable link you can forward in any WhatsApp chat or group. Guests can RSVP directly from the link without leaving WhatsApp.</p></div>
      </div>
      <div class="faq-item">
        <button type="button" class="faq-q">
          What payment methods are supported?
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>We support MTN Mobile Money, Airtel Money, Zamtel Kwacha, Visa &amp; Mastercard, and bank deposits where available. Pro+ plans add invoicing and expanded settlement options across Zambia.</p></div>
      </div>
    </div>
  </div>
</div>

<!-- FINAL CTA -->
<section id="final-cta">
  <h2>Start creating unforgettable<br>invitations today</h2>
  <p>Join thousands of hosts who trust Event Host to make their events shine. Your first invitation is completely free.</p>
  <div class="ctas">
    <button type="button" class="btn-hero-primary" style="font-size:16px;padding:16px 36px">
      <i class="fa-solid fa-gift" aria-hidden="true"></i> Create Free Invitation
    </button>
    <a href="{{ route('templates.index') }}" class="btn-hero-secondary" style="font-size:16px;padding:16px 36px">
      Browse Templates →
    </a>
  </div>
</section>

@endsection
