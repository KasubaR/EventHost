@extends('layouts.site')

@section('title', 'Event Host | Create Beautiful Digital Invitations')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
    <link rel="stylesheet" href="{{ asset('css/event-cards.css') }}">
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
    <div class="section-header">
      <h2>Invitations for every occasion</h2>
      <p>From intimate gatherings to grand celebrations — beautifully crafted for any event.</p>
    </div>
    <div class="event-types-grid">
      <a class="etc-card weddings" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-ring" aria-hidden="true"></i></div>
          <span class="etc-label">Weddings</span>
        </div>
      </a>
      <a class="etc-card birthdays" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-cake-candles" aria-hidden="true"></i></div>
          <span class="etc-label">Birthdays</span>
        </div>
      </a>
      <a class="etc-card graduation" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></div>
          <span class="etc-label">Graduation</span>
        </div>
      </a>
      <a class="etc-card corporate" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></div>
          <span class="etc-label">Corporate</span>
        </div>
      </a>
      <a class="etc-card baby-shower" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-baby" aria-hidden="true"></i></div>
          <span class="etc-label">Baby Shower</span>
        </div>
      </a>
      <a class="etc-card memorial" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-dove" aria-hidden="true"></i></div>
          <span class="etc-label">Memorial</span>
        </div>
      </a>
      <a class="etc-card church" href="{{ auth()->check() ? url('/dashboard') : route('register') }}">
        <div class="etc-inner">
          <div class="etc-icon"><i class="fa-solid fa-church" aria-hidden="true"></i></div>
          <span class="etc-label">Church</span>
        </div>
      </a>
    </div>
    <p class="event-types-note">
      @auth
        <a href="{{ url('/dashboard') }}">Go to your dashboard</a> to create and manage your event invitations.
      @else
        <a href="{{ route('login') }}">Sign in</a> or <a href="{{ route('register') }}">Sign up</a> to create and manage your event invitations.
      @endauth
    </p>
  </div>
</section>

<!-- UPCOMING EVENTS -->
@if ($upcomingEvents->isNotEmpty())
<div id="upcoming-events">
  <div class="section">
    <div class="section-row">
      <h2>Upcoming events</h2>
      <a href="{{ route('events.discover') }}" class="see-all">See all events →</a>
    </div>
    <div class="event-card-grid">
      @foreach ($upcomingEvents as $event)
        @include('events.partials.public-event-card', ['event' => $event])
      @endforeach
    </div>
  </div>
</div>
@endif

<!-- TEMPLATES SHOWCASE -->
@if ($featuredTemplates->isNotEmpty())
<div id="templates">
  <div class="section">
    <div class="section-row">
      <h2>Invitation Templates</h2>
    </div>
    <div class="templates-grid">
      @foreach ($featuredTemplates as $tpl)
        @php
          // Two categories is all the card has room for — several templates
          // belong to five or more.
          $subtitle = $tpl->categories->take(2)->pluck('name')->join(' · ');
        @endphp
        <div class="template-card">
          <div class="tmpl-img">
            <img src="{{ $tpl->preview_image_url }}" alt="{{ $tpl->name }} template preview" width="600" height="800" loading="lazy" decoding="async">
          </div>
          <div class="tmpl-info">
            <h4>{{ $tpl->name }}</h4>
            <p>{{ $subtitle !== '' ? $subtitle : $tpl->requiredTier()->label() }}</p>
          </div>
          <div class="tmpl-overlay">
            <a href="{{ route('templates.preview', $tpl) }}" class="tmpl-btn tmpl-btn-primary">Preview</a>
            @auth
              @if (auth()->user()->canUseInvitationTemplate($tpl))
                <a href="{{ route('events.create', ['template' => $tpl->slug]) }}" class="tmpl-btn tmpl-btn-ghost">Use Template</a>
              @else
                <a href="{{ \App\Support\BillingPlan::checkoutUrlForTier($tpl->requiredTier()) }}" class="tmpl-btn tmpl-btn-ghost">Upgrade to {{ $tpl->requiredTier()->label() }}</a>
              @endif
            @else
              <a href="{{ route('register') }}" class="tmpl-btn tmpl-btn-ghost">Use Template</a>
            @endauth
          </div>
        </div>
      @endforeach
    </div>
  </div>
</div>
@endif

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

{{-- TESTIMONIALS — approved host reviews, featured from the admin panel. --}}
@if ($featuredReviews->isNotEmpty())
<div id="testimonials">
  <div class="section">
    <div class="section-header">
      <h2>Loved by hosts everywhere</h2>
      <p>Don't just take our word for it — hear from people who've used Event Host for their special moments.</p>
    </div>
    <div class="testi-grid">
      @foreach ($featuredReviews as $review)
      @php $videoEmbed = $review->isVideo() ? $review->videoEmbedUrl() : null; @endphp
      <div class="testi-card {{ $videoEmbed ? 'is-video' : '' }}">
        @if ($videoEmbed)
        {{-- The iframe is only built once the viewer clicks — see homepage.js. --}}
        <div class="testi-video" data-testi-video="{{ $videoEmbed }}">
          <button type="button" class="testi-video-play" aria-label="Play the video review from {{ $review->author_name }}">
            @if ($review->video_poster_url)
              <img src="{{ $review->video_poster_url }}" alt="" width="640" height="360" loading="lazy" decoding="async">
            @endif
            <span class="testi-video-icon"><i class="fa-solid fa-play" aria-hidden="true"></i></span>
          </button>
        </div>
        @endif
        @if ($review->rating)
        <div class="stars" aria-label="{{ $review->rating }} out of 5 stars">
          @for ($star = 1; $star <= 5; $star++)
            <i class="fa-solid fa-star {{ $star <= $review->rating ? '' : 'is-empty' }}" aria-hidden="true"></i>
          @endfor
        </div>
        @endif
        <blockquote>&ldquo;{{ $review->body }}&rdquo;</blockquote>
        <div class="testi-author">
          <div class="testi-av"><img src="{{ $review->author_photo_url }}" alt="" width="44" height="44" loading="lazy" decoding="async"></div>
          <div class="testi-info">
            <h4>{{ $review->author_name }}</h4>
            @if ($review->author_context)
              <span>{{ $review->author_context }}</span>
            @endif
          </div>
        </div>
      </div>
      @endforeach
    </div>
  </div>
</div>
@endif

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
          <li>1 free template</li>
          <li>Basic RSVP tracking</li>
          <li>WhatsApp sharing</li>
        </ul>
        <a href="{{ auth()->check() ? route('billing.show', ['plan' => 'base']) : route('register') }}" class="btn-price btn-price-outline">Get Started</a>
      </div>
      <div class="price-card popular">
        <span class="popular-badge">Most Popular</span>
        <div class="price-plan">Pro</div>
        <div class="price-amount"><sup>K</sup>750<span class="period"> / event</span></div>
        <div class="price-desc">For serious hosts who want everything</div>
        <ul class="price-features">
          <li>Unlimited events</li>
          <li>Unlimited guests</li>
          <li>30+ premium templates</li>
          <li>Advanced RSVP dashboard</li>
          <li>WhatsApp reminders</li>
          <li>Photo gallery</li>
          <li>Countdown timer</li>
          <li>Analytics & exports</li>
        </ul>
        <a href="{{ auth()->check() ? route('billing.show', ['plan' => 'pro']) : route('register') }}" class="btn-price btn-price-fill">Get Pro</a>
      </div>
      <div class="price-card">
        <div class="price-plan">Pro+</div>
        <div class="price-amount"><sup>K</sup>1500<span class="period"> / event</span></div>
        <div class="price-desc">For event planners & agencies</div>
        <ul class="price-features">
          <li>Everything in Pro</li>
          <li>Custom branding</li>
          <li>Email + WhatsApp reminders</li>
          <li>Multiple team members</li>
          <li>White-label invitations</li>
          <li>Priority support</li>
          <li>Dedicated account manager</li>
        </ul>
        <a href="{{ auth()->check() ? route('billing.show', ['plan' => 'pro_plus']) : route('contact') }}" class="btn-price btn-price-outline">Contact Sales</a>
      </div>
    </div>
  </div>
</div>

<!-- FAQ — curated in the admin panel; hidden entirely when nothing is published -->
@if ($homepageFaqs->isNotEmpty())
<div id="faq">
  <div class="section">
    <div class="section-header" style="text-align:center">
      <h2>Frequently asked questions</h2>
      <p style="margin:0 auto">Got questions? We've got answers.</p>
    </div>
    <div class="faq-grid">
      @foreach ($homepageFaqs as $faq)
      <div class="faq-item">
        <button type="button" class="faq-q">
          {{ $faq->question }}
          <span class="faq-icon">+</span>
        </button>
        <div class="faq-a"><p>{{ $faq->answer }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</div>
@endif

@endsection
