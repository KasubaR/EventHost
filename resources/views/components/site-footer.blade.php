@php
    $siteName = \App\Models\PlatformSetting::getValue('site_name', config('app.name'));
@endphp

<footer>
    <div class="footer-inner">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="{{ url('/') }}" class="nav-logo">
                    <img src="{{ asset('images/logo/EventHost Logo_Blue.svg') }}" alt="{{ $siteName }}" class="nav-logo-img">
                </a>
                <p>Create stunning digital invitations. Manage RSVPs. Host with confidence.</p>

                <ul class="footer-contact">
                    <li>
                        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                        <a href="mailto:info@eventhostzm.com">info@eventhostzm.com</a>
                    </li>
                    <li>
                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                        <a href="https://wa.me/260965023606" target="_blank" rel="noopener">+260 965 023 606</a>
                    </li>
                    <li>
                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                        <a href="https://wa.me/260971654278" target="_blank" rel="noopener">+260 971 654 278</a>
                    </li>
                    <li>
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        <span>Lusaka, Zambia</span>
                    </li>
                </ul>

                <x-social-links />
            </div>

            <nav class="footer-col" aria-label="Product">
                <h5>Product</h5>
                <a href="{{ url('/#how') }}">How It Works</a>
                <a href="{{ url('/#pricing') }}">Pricing</a>
                <a href="{{ route('events.discover') }}">Discover Events</a>
                <a href="{{ route('register') }}">Create an Event</a>
            </nav>

            <nav class="footer-col" aria-label="Company">
                <h5>Company</h5>
                <a href="{{ route('about') }}">About Us</a>
                <a href="{{ route('contact') }}">Contact Us</a>
            </nav>

            <nav class="footer-col" aria-label="Support">
                <h5>Support</h5>
                <a href="{{ url('/#faq') }}">FAQ</a>
                <a href="{{ route('contact') }}">Get in Touch</a>
                <a href="mailto:info@eventhostzm.com">Email Support</a>
            </nav>

            <nav class="footer-col" aria-label="Legal">
                <h5>Legal</h5>
                <a href="{{ route('legal.privacy') }}">Privacy Policy</a>
                <a href="{{ route('legal.terms') }}">Terms of Service</a>
                <a href="{{ route('legal.cookies') }}">Cookie Policy</a>
            </nav>
        </div>

        <div class="footer-bottom">
            <p>&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</p>
        </div>
    </div>
</footer>
