<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
        <link rel="stylesheet" href="{{ asset('css/media-uploader.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
        <script src="{{ asset('js/media-uploader.js') }}" defer></script>
        <script src="{{ asset('js/admin-hero-upload.js') }}" defer></script>
    @endpush

    @php
        $ev = $adminEvent;
    @endphp

    <x-slot name="title">Ticketing — {{ $ev->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.ticketing.index') }}">Ticketing</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>{{ $ev->name }}</span>
                </nav>
                <h1 class="dph-title">{{ $ev->name }}</h1>
                <p class="dph-sub">{{ $ev->ticketing_status->label() }} · {{ $ev->user?->email }}</p>
            </div>
            <a href="{{ route('admin.ticketing.index') }}" class="evt-btn-outline">Back to queue</a>
        </div>
    </x-slot>

    @if (session('status') === 'ticketing-approved')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket sales approved. The public page is live — checkout ships in a later phase.</div>
    @elseif (session('status') === 'ticketing-rejected')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Activation declined. The organizer can edit and resubmit.</div>
    @elseif (session('status') === 'ticketing-hero-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Hero image saved. It is the banner on the public ticket page.</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="admin-panel-card admin-hero-card">
        <h2>Hero image</h2>
        <p class="admin-muted">Wide banner for the public ticket page. Cropped to 1200×630.</p>
        <img @if ($ev->cover_image) src="{{ $ev->cover_image_url }}" @endif alt="" width="1200" height="630" class="admin-hero-preview" @if (! $ev->cover_image) hidden @endif>
        @unless ($ev->cover_image)
            <p class="admin-muted admin-mt-sm" data-hero-empty>No hero image yet. Upload one before approving ticket sales.</p>
        @endunless

        @if (auth('admin')->user()?->can('ticketing.approve'))
            <form method="post" action="{{ route('admin.ticketing.hero', $ev) }}" enctype="multipart/form-data" class="profile-form admin-mt-md">
                @csrf
                <div class="admin-hero-upload" data-upload-queue>
                    <label class="admin-hero-pick" for="hero_image">
                        <i class="fa-solid fa-image" aria-hidden="true"></i>
                        <span data-hero-label>{{ $ev->cover_image ? 'Replace hero image' : 'Upload hero image' }}</span>
                        <input id="hero_image" name="hero_image" type="file" accept="image/jpeg,image/png,image/webp"
                               data-upload-slot="cover"
                               data-upload-url="{{ route('admin.ticketing.hero', $ev) }}"
                               data-upload-max-bytes="{{ \App\Support\InvitationMediaRules::COVER_MAX_KB * 1024 }}"
                               data-upload-commit="1">
                    </label>
                </div>
                <p class="admin-muted">JPEG, PNG or WebP up to 4 MB.</p>
                @error('hero_image')
                    <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                @enderror
                @error('file')
                    <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                @enderror
                <noscript>
                    <div class="admin-actions admin-mt-sm">
                        <button type="submit" class="btn-primary">Save hero</button>
                    </div>
                </noscript>
            </form>
        @endif
    </div>

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Summary</h2>
            <p class="admin-muted admin-mt-sm">{{ $ev->event_type_label }} · {{ $ev->event_date?->format('j M Y') }}</p>
            <p class="admin-muted">Commission mode: {{ $ev->commission_mode?->label() ?? '—' }}</p>
            <p class="admin-muted">Platform commission: {{ \App\Support\TicketingSettings::commissionPercent() }}%</p>
            <p class="admin-muted">Published: {{ $ev->is_published ? 'Yes' : 'No' }}</p>
            @if ($ev->ticketing_rejection_note)
                <p class="admin-muted">Last note: {{ $ev->ticketing_rejection_note }}</p>
            @endif

            @if ($ev->ticketing_status === \App\Enums\TicketingStatus::PendingReview && auth('admin')->user()?->can('ticketing.approve'))
                <form method="post" action="{{ route('admin.ticketing.approve', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    <label for="agreed_payout_on">Agreed payout date <span class="profile-optional">optional</span></label>
                    <input id="agreed_payout_on" name="agreed_payout_on" type="date" data-dtp
                           class="profile-input" value="{{ old('agreed_payout_on') }}"
                           min="{{ $ev->event_date?->format('Y-m-d') }}">
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary" data-hero-approve @disabled(! $ev->cover_image)>Approve ticket sales</button>
                    </div>
                </form>

                <form method="post" action="{{ route('admin.ticketing.reject', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    <label for="ticketing_rejection_note">Decline reason</label>
                    <textarea id="ticketing_rejection_note" name="ticketing_rejection_note" class="profile-input" rows="3" required maxlength="2000">{{ old('ticketing_rejection_note') }}</textarea>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Decline</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Ticket types</h2>
            @if ($ev->ticketTypes->isEmpty())
                <p class="admin-muted admin-mt-sm">No ticket types.</p>
            @else
                <ul class="tkt-type-list admin-mt-sm">
                    @foreach ($ev->ticketTypes as $type)
                        <li class="tkt-type-row">
                            <div>
                                <strong>{{ $type->name }}</strong>
                                <p class="evt-muted">
                                    {{ \App\Support\TicketingSettings::formatZmw($type->price) }}
                                    · {{ $type->quantity === null ? 'Unlimited' : number_format($type->quantity).' available' }}
                                </p>
                            </div>
                            <span class="tkt-sales-status {{ $type->is_active ? 'tkt-sales-status--on' : 'tkt-sales-status--off' }}">
                                {{ $type->is_active ? 'Active' : 'Hidden' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if((auth('admin')->user()?->can('users.view') && $ev->user) || auth('admin')->user()?->can('events.view'))
                <div class="admin-panel-links admin-mt-md">
                    @if(auth('admin')->user()?->can('users.view') && $ev->user)
                        <a href="{{ route('admin.users.show', $ev->user) }}" class="admin-panel-link">
                            <i class="fa-solid fa-user"></i> Organizer account
                        </a>
                    @endif
                    @if(auth('admin')->user()?->can('events.view'))
                        <a href="{{ route('admin.events.show', $ev) }}" class="admin-panel-link">
                            <i class="fa-solid fa-calendar-days"></i> Event record
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
