@php
    $siteName = \App\Models\PlatformSetting::getValue('site_name', config('app.name'));
@endphp

<footer>
    <div class="footer-inner">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="{{ url('/') }}" class="nav-logo footer-logo-link" aria-label="{{ $siteName }}">
                    {{-- Cropped viewBox: the source file is a 540×540 square with the
                         mark sitting in the middle, so a full-height img is mostly padding. --}}
                    <svg class="footer-logo" xmlns="http://www.w3.org/2000/svg" viewBox="26 200 494 140" role="img" aria-hidden="true" focusable="false">
                        <g fill="#1e47bb">
                            <path d="m175.42,242.29h34.19v9.26h-23.83v10.62h22.95v9.19h-22.95v10.69h24.45v9.26h-34.8v-49.03Z"/>
                            <path d="m212.2,253.86h11.51l7.29,28.06,7.35-28.06h11.44l-11.85,37.46h-13.96l-11.78-37.46Z"/>
                            <path d="m251.29,272.73c0-12.6,8.72-19.54,18.46-19.54,8.17,0,17.5,4.97,17.43,17.57v5.04h-25.33c.88,4.7,3.88,7.56,8.92,7.56s7.15-2.38,8.04-4.36l6.95,4.56c-2.52,5.24-7.49,8.45-15.12,8.45-11.37,0-19.34-7.22-19.34-19.27Zm26.63-3.13v-.68c0-3.47-2.66-6.81-7.76-6.81-3.75,0-7.35,2.18-8.31,7.49h16.07Z"/>
                            <path d="m312.85,262.65c-5.24,0-8.1,3.13-9.26,7.7v20.97h-10.28v-37.46h9.6l.68,3.81.61.2c1.97-2.11,4.36-4.7,10.96-4.7,8.38,0,14.64,4.15,14.64,13.14v24.99h-10.35v-22.4c0-4.97-3.47-6.27-6.61-6.27Z"/>
                            <path d="m341.12,278.31v-15.32h-6.74v-9.12h7.63l.68-9.6h8.51v9.6h9.47v9.12h-9.12v14.51c0,2.79,1.02,4.15,4.09,4.15h3.95v9.67h-7.36c-7.7,0-11.1-5.31-11.1-13.01Z"/>
                            <path d="m396.62,268.1h-25.74v23.22h-4.36v-49.03h4.36v21.79h25.74v-21.79h4.36v49.03h-4.36v-23.22Z"/>
                            <path d="m408.61,272.66c0-12.94,8.92-19.48,19.75-19.48s19.61,6.61,19.61,19.48-8.92,19.34-19.61,19.34-19.75-6.61-19.75-19.34Zm34.94,0c0-10.56-6.74-15.53-15.19-15.53s-15.32,5.04-15.32,15.53,6.74,15.39,15.32,15.39,15.19-5.04,15.19-15.39Z"/>
                            <path d="m453.69,281.78l3.75-.95c1.09,3.47,4.36,7.56,11.37,7.56s10.15-3.61,10.15-7.63c0-10.62-23.63-3.34-23.63-16.82,0-7.15,6.4-10.76,13.69-10.76,6.54,0,12.39,3.06,13.35,9.94l-3.54,1.16c-1.16-5.65-5.18-7.49-9.81-7.49-5.58,0-9.53,2.79-9.53,6.67,0,9.46,23.49,2.93,23.49,16.82,0,6.2-4.63,11.71-14.3,11.71-8.38,0-13.62-4.15-14.98-10.21Z"/>
                            <path d="m493.46,282.33v-24.58h-6.27v-3.88h6.61l.68-8.04h3.2v8.04h9.06v3.88h-8.99v25.26c0,2.79.82,4.29,3.81,4.29h4.02v4.02h-4.9c-6.27,0-7.22-3.95-7.22-8.99Z"/>
                            <path d="m96.58,204.09c-36.25,0-65.91,29.66-65.91,65.91s29.66,65.91,65.91,65.91,65.92-29.44,65.92-65.91v-65.91h-65.92Zm-28.97,36.44h28.74v9.17h-37.91c0-5.06,4.11-9.17,9.17-9.17Zm42.92,56.24h-10.19v-19.65h10.19v19.65Zm33.05,0h-10.19v-19.94c4.06-.76,7.63-2.92,10.19-5.97v25.91Zm0-37.07c0,7.38-5.99,13.36-13.37,13.36h-16.17v.06h-39.92c-3.04,0-5.5,2.45-5.5,5.49v9.15h28.35c0,5.06-4.1,9.16-9.16,9.16h-29.37v-16.78c0-8.89,7.22-16.11,16.11-16.11h25.79v-23.55h10.19v23.55h18.73c2.28,0,4.13-1.85,4.13-4.14v-19.41h10.19v19.22Z"/>
                        </g>
                    </svg>
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
            <a href="https://kinpinarts.com/" class="footer-byline" target="_blank" rel="noopener noreferrer">
                <span>A product of</span>
                <img src="{{ asset('images/logo/arts.svg') }}" alt="Kinpin Arts Media" width="120" height="42">
            </a>
        </div>
    </div>
</footer>
