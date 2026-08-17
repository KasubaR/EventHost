<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/events-form.js') }}" defer></script>
    @endpush

    <x-slot name="title">Ticketing settings — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Ticketing</h1>
                <p class="dph-sub">{{ $event->name }} · {{ $event->ticketing_status->label() }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if ($setupMode)
        @include('events.partials.steps', ['current' => 3, 'ticketed' => true])
    @else
        @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'settings'])
    @endif

    @if (session('status') === 'draft-saved')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Draft saved — add your ticket types below.</div>
    @elseif (session('status') === 'ticket-type-created')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket type added.</div>
    @elseif (session('status') === 'ticket-type-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket type saved.</div>
    @elseif (session('status') === 'ticket-type-deleted')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket type removed.</div>
    @elseif (session('status') === 'ticketing-settings-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Ticket sales settings saved.</div>
    @elseif (session('status') === 'ticketing-submitted')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Submitted for EventHost review. Ticket sales stay off until we approve.</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="evt-stack">
        @include('events.tickets.partials.rejection-note', ['event' => $event])

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Ticket Sales</h2>
                <p>Payment infrastructure is set by EventHost. You choose how the commission is applied.</p>
            </div>
            <div class="evt-section-body">
                <div class="tkt-summary-row">
                    <span class="tkt-sales-status {{ $event->ticketSalesAreApproved() ? 'tkt-sales-status--on' : 'tkt-sales-status--off' }}">
                        <i class="fa-solid {{ $event->ticketSalesAreApproved() ? 'fa-circle-check' : 'fa-circle-pause' }}"></i>
                        {{ $event->ticketSalesAreApproved() ? 'Online ticket sales enabled' : 'Off until EventHost activates this event' }}
                    </span>
                    <span class="tkt-fact-chip">{{ rtrim(rtrim($commissionPercent, '0'), '.') }}% commission</span>
                    <span class="tkt-fact-chip"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Money / Bank Transfer</span>
                    @if ($event->agreed_payout_on)
                        <span class="tkt-fact-chip"><i class="fa-solid fa-calendar-check"></i> Payout {{ $event->agreed_payout_on->format('j M Y') }}</span>
                    @endif
                </div>
                <p class="evt-muted tkt-locked-note">These values cannot be changed per event.</p>

                {{-- Step 3 of the wizard auto-saves on pick — data-auto-submit tells
                     events-form.js to submit on radio change instead of showing a
                     separate Save button. Outside the wizard (Settings), the pick still
                     needs an explicit save. --}}
                <form method="post" action="{{ route('events.ticketing.update', $event) }}" class="tkt-commission-form"
                      @if ($setupMode) data-auto-submit @endif>
                    @csrf
                    @method('PATCH')
                    <div class="profile-field">
                        <div class="tkt-commission-heading">
                            <h3>Who Pays the Commission</h3>
                            <p class="evt-muted">Choose whether buyers or you absorb the {{ $commissionPercent }}% fee.</p>
                        </div>
                        <div class="evt-product-choice" @if (! $event->canEditCommissionMode()) aria-disabled="true" @endif>
                            <label class="evt-product-choice-card">
                                <input type="radio" name="commission_mode" value="absorb" class="evt-check-input"
                                       @checked(old('commission_mode', $event->commission_mode?->value) === 'absorb')
                                       @disabled(! $event->canEditCommissionMode())>
                                <span>
                                    <strong>Deducted from my earnings</strong>
                                    <span class="evt-product-choice-hint">Buyers pay the listed ticket price. The {{ $commissionPercent }}% commission comes out of what you receive.</span>
                                </span>
                            </label>
                            <label class="evt-product-choice-card">
                                <input type="radio" name="commission_mode" value="pass_through" class="evt-check-input"
                                       @checked(old('commission_mode', $event->commission_mode?->value) === 'pass_through')
                                       @disabled(! $event->canEditCommissionMode())>
                                <span>
                                    <strong>Added to the buyer’s price</strong>
                                    <span class="evt-product-choice-hint">Buyers pay the ticket price plus {{ $commissionPercent }}%. You receive the listed price.</span>
                                </span>
                            </label>
                        </div>
                        @error('commission_mode')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    @if ($event->canEditCommissionMode() && ! $setupMode)
                        <div class="evt-actions-bar">
                            <button type="submit" class="btn-primary">Save commission setting</button>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head evt-section-head--with-action">
                <div>
                    <h2>Ticket Types</h2>
                    <p>Named prices and quantities buyers will choose from.</p>
                </div>
                <a href="{{ route('events.ticket-types.create', $event) }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Add ticket type</a>
            </div>
            <div class="evt-section-body">
                @if ($ticketTypes->isEmpty())
                    <p class="evt-muted">No ticket types yet. Add at least one before requesting activation.</p>
                @else
                    <ul class="tkt-type-list">
                        @foreach ($ticketTypes as $type)
                            <li class="tkt-type-row">
                                <div>
                                    <strong>{{ $type->name }}</strong>
                                    <p class="evt-muted">{{ \App\Support\TicketingSettings::formatZmw($type->price) }}
                                        · {{ $type->quantity === null ? 'Unlimited' : number_format($type->quantity).' available' }}
                                        · {{ $type->is_active ? 'Active' : 'Hidden' }}
                                    </p>
                                </div>
                                <div class="evt-card-actions">
                                    <a href="{{ route('events.ticket-types.edit', [$event, $type]) }}" class="evt-btn-outline">Edit</a>
                                    <form method="post" action="{{ route('events.ticket-types.destroy', [$event, $type]) }}" data-confirm="Remove this ticket type?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Delete</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @if ($setupMode)
            {{-- Submitting for activation is the closing action of step 4, not
                 here — reaching it requires passing through Review & publish
                 first, so review never gets skipped. --}}
            <div class="evt-section">
                <div class="evt-section-body evt-actions-bar">
                    <a href="{{ route('events.edit', $event) }}" class="btn-primary">
                        Continue to review &amp; publish <i class="fa-solid fa-arrow-right"></i>
                    </a>
                    <span class="evt-muted">Review your event details, then submit for activation there.</span>
                </div>
            </div>
        @else
            @include('events.tickets.partials.activation-panel', ['event' => $event, 'ticketTypes' => $ticketTypes])
        @endif
    </div>
</x-app-layout>
