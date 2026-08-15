@extends('layouts.site')

@section('title', 'Contact Us | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/contact.css') }}">
@endpush

@section('content')

<!-- HERO -->
<section class="contact-hero">
    <div class="contact-hero-inner">
        <h1>We'd love to hear from you</h1>
        <p>Have a question, a feature request, or just want to say hello? Our team is here and happy to help.</p>
    </div>
</section>

<!-- MAIN CONTENT -->
<section class="contact-main">
    <div class="contact-inner">

        <!-- Contact Info -->
        <div class="contact-info">
            <h2>Contact information</h2>
            <p class="contact-info-sub">Reach us through any of the channels below, or fill in the form and we'll get back to you within one business day.</p>

            <div class="contact-info-items">
                <div class="contact-info-item">
                    <div class="contact-info-icon" style="background:rgba(30,71,187,0.12);color:var(--accent)">
                        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="contact-info-label">Email</div>
                        <a href="mailto:info@eventhostzm.com" class="contact-info-value">info@eventhostzm.com</a>
                    </div>
                </div>
                <div class="contact-info-item">
                    <div class="contact-info-icon" style="background:rgba(39,174,96,0.12);color:var(--green)">
                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="contact-info-label">WhatsApp</div>
                        <div class="contact-info-stack">
                            <a href="https://wa.me/260965023606" class="contact-info-value" target="_blank" rel="noopener">+260 965 023 606</a>
                            <a href="https://wa.me/260971654278" class="contact-info-value" target="_blank" rel="noopener">+260 971 654 278</a>
                        </div>
                    </div>
                </div>
                <div class="contact-info-item">
                    <div class="contact-info-icon" style="background:rgba(224,14,79,0.12);color:var(--pink)">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="contact-info-label">Head office</div>
                        <address class="contact-info-value contact-info-address">
                            <a href="https://kinpinarts.com/" target="_blank" rel="noopener noreferrer">Kinpin Arts Media</a><br>
                            Unit C11, Chamba Valley Shopping Plaza<br>
                            Maposa Road, Lusaka, Zambia
                        </address>
                    </div>
                </div>
                <div class="contact-info-item">
                    <div class="contact-info-icon" style="background:rgba(224,14,79,0.12);color:var(--pink)">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="contact-info-label">Solwezi branch</div>
                        <address class="contact-info-value contact-info-address">
                            Unit 4, Plot 5049, Mushitala<br>
                            Off Kansanshi Road, Solwezi, Zambia
                        </address>
                    </div>
                </div>
                <div class="contact-info-item">
                    <div class="contact-info-icon" style="background:rgba(0,206,201,0.12);color:var(--cyan)">
                        <i class="fa-solid fa-clock" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="contact-info-label">Response time</div>
                        <div class="contact-info-value">Within 1 business day</div>
                    </div>
                </div>
            </div>

            @if (collect(config('social'))->filter()->isNotEmpty())
                <div class="contact-socials">
                    <p class="contact-socials-label">Follow us</p>
                    <x-social-links />
                </div>
            @endif
        </div>

        <!-- Contact Form -->
        <div class="contact-form-wrap">
            @if(session('success'))
                <div class="contact-success">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <div>
                        <strong>Message sent!</strong>
                        <p>Thanks for reaching out. We'll get back to you within one business day.</p>
                    </div>
                </div>
            @endif

            @if(session('contact_error'))
                <div class="contact-error">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <div>
                        <strong>We couldn't send your message</strong>
                        <p>Something went wrong on our side. Please email us directly at <a href="mailto:info@eventhostzm.com">info@eventhostzm.com</a> and we'll pick it up from there.</p>
                    </div>
                </div>
            @endif

            <form action="{{ route('contact.store') }}" method="POST" class="contact-form" novalidate>
                @csrf
                <div class="contact-form-row">
                    <div class="contact-field">
                        <label for="contact_name">Your name <span aria-hidden="true">*</span></label>
                        <input type="text" id="contact_name" name="name" value="{{ old('name') }}"
                               placeholder="John Banda" required autocomplete="name"
                               class="{{ $errors->has('name') ? 'is-error' : '' }}">
                        @error('name')
                            <span class="contact-field-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="contact-field">
                        <label for="contact_email">Email address <span aria-hidden="true">*</span></label>
                        <input type="email" id="contact_email" name="email" value="{{ old('email') }}"
                               placeholder="john@example.com" required autocomplete="email"
                               class="{{ $errors->has('email') ? 'is-error' : '' }}">
                        @error('email')
                            <span class="contact-field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
                <div class="contact-field">
                    <label for="contact_subject">Subject <span aria-hidden="true">*</span></label>
                    <select id="contact_subject" name="subject"
                            class="{{ $errors->has('subject') ? 'is-error' : '' }}">
                        <option value="" disabled {{ old('subject') ? '' : 'selected' }}>Select a topic…</option>
                        <option value="General enquiry" {{ old('subject') === 'General enquiry' ? 'selected' : '' }}>General enquiry</option>
                        <option value="Technical support" {{ old('subject') === 'Technical support' ? 'selected' : '' }}>Technical support</option>
                        <option value="Billing & payments" {{ old('subject') === 'Billing & payments' ? 'selected' : '' }}>Billing &amp; payments</option>
                        <option value="Feature request" {{ old('subject') === 'Feature request' ? 'selected' : '' }}>Feature request</option>
                        <option value="Partnership" {{ old('subject') === 'Partnership' ? 'selected' : '' }}>Partnership</option>
                        <option value="Other" {{ old('subject') === 'Other' ? 'selected' : '' }}>Other</option>
                    </select>
                    @error('subject')
                        <span class="contact-field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="contact-field">
                    <label for="contact_message">Message <span aria-hidden="true">*</span></label>
                    <textarea id="contact_message" name="message" rows="6"
                              placeholder="Tell us how we can help…" required
                              class="{{ $errors->has('message') ? 'is-error' : '' }}">{{ old('message') }}</textarea>
                    @error('message')
                        <span class="contact-field-error">{{ $message }}</span>
                    @enderror
                </div>
                <button type="submit" class="contact-submit">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send message
                </button>
            </form>
        </div>

    </div>
</section>

<!-- FAQ STRIP — curated in the admin panel; hidden when nothing is published -->
@if ($contactFaqs->isNotEmpty())
<section class="contact-faq">
    <div class="contact-faq-inner">
        <div class="contact-faq-header">
            <span class="contact-label">Quick answers</span>
            <h2>Frequently asked questions</h2>
        </div>
        <div class="contact-faq-grid">
            @foreach ($contactFaqs as $faq)
            <div class="contact-faq-item">
                <h3>{{ $faq->question }}</h3>
                <p>{{ $faq->answer }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@endsection
