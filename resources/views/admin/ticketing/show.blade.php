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
        <script src="{{ asset('js/events-form.js') }}" defer></script>
    @endpush

    @php
        $ev = $adminEvent;
        $canApprove = auth('admin')->user()?->can('ticketing.approve');
        $activatableStatuses = [
            \App\Enums\TicketingStatus::Draft,
            \App\Enums\TicketingStatus::Rejected,
            \App\Enums\TicketingStatus::PendingReview,
        ];
        $canActivate = $canApprove && in_array($ev->ticketing_status, $activatableStatuses, true);
        $hasActiveType = $ev->ticketTypes->contains(fn ($type) => $type->is_active);
        $commissionPercent = $commissionPercent ?? $ev->commissionPercent();
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
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket sales approved. The public page is live.</div>
    @elseif (session('status') === 'ticketing-rejected')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Activation declined. The organizer can edit and resubmit.</div>
    @elseif (session('status') === 'ticketing-hero-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Hero image saved. It is the banner on the public ticket page.</div>
    @elseif (session('status') === 'ticketing-terms-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Negotiated terms saved.</div>
    @elseif (session('status') === 'ticketing-commission-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Commission mode saved.</div>
    @elseif (session('status') === 'ticketed-event-created')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticketed draft created for the client. Add ticket types and a hero, then activate sales.</div>
    @elseif (session('status') === 'ticket-type-created')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket type added.</div>
    @elseif (session('status') === 'ticket-type-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket type saved.</div>
    @elseif (session('status') === 'ticket-type-deleted')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket type removed.</div>
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

        @if ($canApprove)
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

            <dl class="admin-fact-grid">
                <div class="admin-fact">
                    <dt>Type</dt>
                    <dd>
                        {{ $ev->event_type_label }}
                        @if ($ev->event_date)
                            <span class="admin-fact-sub">{{ $ev->event_date->format('j M Y') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="admin-fact">
                    <dt>Commission mode</dt>
                    <dd>{{ $ev->commission_mode?->label() ?? '—' }}</dd>
                </div>
                <div class="admin-fact">
                    <dt>Commission rate</dt>
                    <dd>
                        {{ $ev->commissionPercent() }}%
                        <span class="admin-fact-sub">{{ $ev->hasCommissionOverride() ? '(custom)' : '(platform default)' }}</span>
                    </dd>
                </div>
                <div class="admin-fact">
                    <dt>Cancellation fee</dt>
                    <dd>
                        {{ $ev->cancellationFeePercent() }}%
                        <span class="admin-fact-sub">{{ $ev->hasCancellationFeeOverride() ? '(custom)' : '(platform default)' }}</span>
                    </dd>
                </div>
                <div class="admin-fact">
                    <dt>Ticket types</dt>
                    <dd>{{ number_format($ev->ticketTypes->count()) }}</dd>
                </div>
            </dl>

            @if ($canApprove && $ev->canEditCommissionMode())
                <form method="post" action="{{ route('admin.ticketing.commission', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    @method('PATCH')
                    <div class="profile-field">
                        <div class="tkt-commission-heading">
                            <h3>Who pays the commission</h3>
                            <p class="admin-muted">{{ rtrim(rtrim((string) $commissionPercent, '0'), '.') }}% EventHost commission.</p>
                        </div>
                        <div class="evt-product-choice">
                            <label class="evt-product-choice-card">
                                <input type="radio" name="commission_mode" value="absorb" class="evt-check-input"
                                       @checked(old('commission_mode', $ev->commission_mode?->value) === 'absorb')>
                                <span>
                                    <strong>Deducted from host earnings</strong>
                                    <span class="evt-product-choice-hint">Buyers pay the listed price.</span>
                                </span>
                            </label>
                            <label class="evt-product-choice-card">
                                <input type="radio" name="commission_mode" value="pass_through" class="evt-check-input"
                                       @checked(old('commission_mode', $ev->commission_mode?->value) === 'pass_through')>
                                <span>
                                    <strong>Added to the buyer’s price</strong>
                                    <span class="evt-product-choice-hint">Host receives the listed price.</span>
                                </span>
                            </label>
                        </div>
                        @error('commission_mode')
                            <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary">Save commission mode</button>
                    </div>
                </form>
            @endif

            @if ($canApprove)
                <form method="post" action="{{ route('admin.ticketing.terms', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    @method('PATCH')
                    <fieldset>
                        <legend class="admin-muted">Negotiated terms <span class="profile-optional">blank = platform default</span></legend>
                        <label for="commission_percent_override" class="admin-mt-sm">Commission % for this event</label>
                        <input id="commission_percent_override" name="commission_percent_override" type="number" step="0.01" min="0" max="100"
                               class="profile-input {{ $errors->has('commission_percent_override') ? 'profile-input--error' : '' }}"
                               placeholder="{{ \App\Support\TicketingSettings::commissionPercent() }}"
                               value="{{ old('commission_percent_override', $ev->commission_percent_override) }}">
                        @error('commission_percent_override')
                            <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror

                        <label for="cancellation_fee_percent_override" class="admin-mt-sm">Cancellation fee % for this event</label>
                        <input id="cancellation_fee_percent_override" name="cancellation_fee_percent_override" type="number" step="0.01" min="0" max="100"
                               class="profile-input {{ $errors->has('cancellation_fee_percent_override') ? 'profile-input--error' : '' }}"
                               placeholder="{{ \App\Support\TicketingSettings::cancellationFeePercent() }}"
                               value="{{ old('cancellation_fee_percent_override', $ev->cancellation_fee_percent_override) }}">
                        @error('cancellation_fee_percent_override')
                            <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </fieldset>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary">Save terms</button>
                    </div>
                </form>
            @endif

            @if ($ev->ticketing_rejection_note)
                <div class="admin-callout admin-callout--danger">
                    <div class="admin-callout-icon" aria-hidden="true">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <div>
                        <p class="admin-callout-kicker">Last note</p>
                        <p class="admin-callout-body">{{ $ev->ticketing_rejection_note }}</p>
                    </div>
                </div>
            @endif

            @if ($canActivate)
                <form method="post" action="{{ route('admin.ticketing.approve', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    <label for="agreed_payout_on">Agreed payout date <span class="profile-optional">optional</span></label>
                    <input id="agreed_payout_on" name="agreed_payout_on" type="date" data-dtp
                           class="profile-input" value="{{ old('agreed_payout_on') }}"
                           min="{{ $ev->event_date?->format('Y-m-d') }}">
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary" data-hero-approve
                                @disabled(! $ev->cover_image || ! $hasActiveType)>
                            Approve ticket sales
                        </button>
                    </div>
                    @unless ($hasActiveType)
                        <p class="admin-muted admin-mt-sm">Add at least one active ticket type before activating.</p>
                    @endunless
                </form>
            @endif

            @if ($ev->ticketing_status === \App\Enums\TicketingStatus::PendingReview && $canApprove)
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
            <div class="evt-section-head evt-section-head--with-action">
                <div>
                    <h2>Ticket types</h2>
                </div>
                @if ($canApprove)
                    <a href="{{ route('admin.ticketing.ticket-types.create', $ev) }}" class="evt-btn-outline evt-btn-tiny">
                        <i class="fa-solid fa-plus"></i> Add type
                    </a>
                @endif
            </div>
            @if ($ev->ticketTypes->isEmpty())
                <p class="admin-muted admin-mt-sm">No ticket types.</p>
            @else
                <ul class="tkt-type-list admin-mt-sm">
                    @foreach ($ev->ticketTypes as $type)
                        <li class="tkt-type-row">
                            <div>
                                <span class="tkt-type-name">{{ $type->name }}</span>
                                <p class="admin-muted">
                                    {{ \App\Support\TicketingSettings::formatZmw($type->price) }}
                                    · {{ $type->quantity === null ? 'Unlimited' : number_format($type->quantity).' available' }}
                                </p>
                            </div>
                            <div class="evt-card-actions">
                                <span class="tkt-sales-status {{ $type->is_active ? 'tkt-sales-status--on' : 'tkt-sales-status--off' }}">
                                    {{ $type->is_active ? 'Active' : 'Hidden' }}
                                </span>
                                @if ($canApprove)
                                    <a href="{{ route('admin.ticketing.ticket-types.edit', [$ev, $type]) }}" class="evt-btn-outline evt-btn-tiny">Edit</a>
                                    <form method="post" action="{{ route('admin.ticketing.ticket-types.destroy', [$ev, $type]) }}"
                                          data-confirm="Remove this ticket type?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="evt-btn-outline evt-btn-tiny evt-btn-danger-outline">Delete</button>
                                    </form>
                                @endif
                            </div>
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
