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
                <a href="{{ route('events.ticket-types.create', $event) }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Add ticket type</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'settings'])

    @if (session('status') === 'ticket-type-created')
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
        @if ($event->ticketing_status === \App\Enums\TicketingStatus::Rejected && $event->ticketing_rejection_note)
            <div class="evt-flash evt-flash--warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Activation was declined: {{ $event->ticketing_rejection_note }}
            </div>
        @endif

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Ticket sales</h2>
                <p>Payment infrastructure is set by EventHost. You choose how the commission is applied.</p>
            </div>
            <div class="evt-section-body">
                <dl class="tkt-locked-facts">
                    <div>
                        <dt>Online ticket sales</dt>
                        <dd>{{ $event->ticketSalesAreApproved() ? 'Enabled' : 'Off until EventHost activates this event' }}</dd>
                    </div>
                    <div>
                        <dt>Payment provider</dt>
                        <dd>EventHost / Lenco</dd>
                    </div>
                    <div>
                        <dt>EventHost Ticketing Commission</dt>
                        <dd>{{ $commissionPercent }}%</dd>
                    </div>
                    <div>
                        <dt>Customer payment method</dt>
                        <dd>Mobile Money / Bank Transfer</dd>
                    </div>
                    @if ($event->agreed_payout_on)
                        <div>
                            <dt>Agreed payout date</dt>
                            <dd>{{ $event->agreed_payout_on->format('j M Y') }}</dd>
                        </div>
                    @endif
                </dl>
                <p class="evt-muted tkt-locked-note">These values cannot be changed per event.</p>

                <form method="post" action="{{ route('events.ticketing.update', $event) }}" class="profile-fields tkt-commission-form">
                    @csrf
                    @method('PATCH')
                    <span class="profile-label">Who pays the commission</span>
                    <label class="profile-label evt-check-label">
                        <input type="radio" name="commission_mode" value="absorb" class="evt-check-input"
                               @checked(old('commission_mode', $event->commission_mode?->value) === 'absorb')
                               @disabled(! $event->canEditCommissionMode())>
                        Deducted from my earnings
                    </label>
                    <label class="profile-label evt-check-label">
                        <input type="radio" name="commission_mode" value="pass_through" class="evt-check-input"
                               @checked(old('commission_mode', $event->commission_mode?->value) === 'pass_through')
                               @disabled(! $event->canEditCommissionMode())>
                        Added to the buyer’s price
                    </label>
                    @error('commission_mode')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                    @if ($event->canEditCommissionMode())
                        <div class="evt-actions-bar">
                            <button type="submit" class="evt-btn-outline">Save commission setting</button>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Ticket types</h2>
                <p>Named prices and quantities buyers will choose from.</p>
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

        @if ($event->canSubmitTicketing())
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>Request activation</h2>
                    <p>EventHost reviews ticketed events before buyers can pay.</p>
                </div>
                <div class="evt-section-body evt-actions-bar">
                    <form method="post" action="{{ route('events.ticketing.submit', $event) }}" data-confirm="Submit this event for EventHost to activate ticket sales?">
                        @csrf
                        <button type="submit" class="btn-primary" @disabled($ticketTypes->where('is_active', true)->isEmpty())>
                            <i class="fa-solid fa-paper-plane"></i> Submit for activation
                        </button>
                    </form>
                    @if ($ticketTypes->where('is_active', true)->isEmpty())
                        <span class="evt-muted">Add an active ticket type first.</span>
                    @endif
                </div>
            </div>
        @elseif ($event->ticketing_status === \App\Enums\TicketingStatus::PendingReview)
            <div class="evt-section">
                <div class="evt-section-body">
                    <p class="evt-muted">Submitted {{ $event->ticketing_submitted_at?->format('j M Y, H:i') }}. Ticket sales stay off until EventHost approves.</p>
                </div>
            </div>
        @elseif ($event->ticketSalesAreApproved())
            <div class="evt-section">
                <div class="evt-section-body">
                    <p class="evt-muted">Public ticket page: <a href="{{ route('events.public', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug) }}</a></p>
                    <p class="evt-muted">Checkout is not live yet — buyers cannot pay until the next ticketing phase ships.</p>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
