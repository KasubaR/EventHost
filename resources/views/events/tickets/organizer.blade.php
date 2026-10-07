<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
        <script src="{{ asset('js/events-form.js') }}" defer></script>
    @endpush

    <x-slot name="title">Organizer details | {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Organizer details</h1>
                <p class="dph-sub">{{ $event->name }} · {{ $event->ticketing_status->label() }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('public-events.ticket-types.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Tickets</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-eye"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if ($setupMode)
        @include('events.partials.steps', ['current' => 4, 'ticketed' => true])
    @else
        @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'organizer'])
    @endif

    @if (session('status') === 'organizer-saved')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Organizer details saved.</div>
    @elseif (session('status') === 'organizer-saved-payout-missing')
        <div class="evt-flash evt-flash--info" role="status"><i class="fa-solid fa-circle-info"></i> Organizer details saved. The payout account still has to be added by the account owner before this event can be reviewed.</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('public-events.organizer.update', $event) }}" class="profile-form evt-stack">
        @csrf
        @method('PATCH')

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Organizer</h2>
                <p>Who guests can ask about the event itself: the programme, the venue and the day.</p>
            </div>
            <div class="evt-section-body profile-fields">
                <div class="profile-field">
                    <label for="organizer_name" class="profile-label">Organizer name</label>
                    <input id="organizer_name" name="organizer_name" type="text" required maxlength="120"
                           class="profile-input {{ $errors->has('organizer_name') ? 'profile-input--error' : '' }}"
                           value="{{ old('organizer_name', $event->organizer_name ?? $event->user?->company_name ?? $event->user?->name) }}"
                           placeholder="e.g. Nsobe Game Camp">
                    @error('organizer_name')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="evt-grid-2">
                    <div class="profile-field">
                        <label for="organizer_phone" class="profile-label">Contact number</label>
                        <input id="organizer_phone" name="organizer_phone" type="tel" required maxlength="40" autocomplete="tel"
                               class="profile-input {{ $errors->has('organizer_phone') ? 'profile-input--error' : '' }}"
                               value="{{ old('organizer_phone', $event->organizer_phone ?? $event->hostContactPhone() ?? '') }}"
                               placeholder="e.g. 0977 123 456">
                        @error('organizer_phone')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="organizer_email" class="profile-label">Contact email</label>
                        <input id="organizer_email" name="organizer_email" type="email" required maxlength="255" autocomplete="email"
                               class="profile-input {{ $errors->has('organizer_email') ? 'profile-input--error' : '' }}"
                               value="{{ old('organizer_email', $event->organizer_email ?? $event->user?->email ?? '') }}"
                               placeholder="e.g. reservations@example.com">
                        @error('organizer_email')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="profile-field">
                    <span class="profile-label">Show these details on the event page?</span>
                    @php $showPublic = (string) old('organizer_details_public', ($event->organizer_details_public ?? true) ? '1' : '0'); @endphp
                    <div class="evt-product-choice">
                        <label class="evt-product-choice-card">
                            <input type="radio" name="organizer_details_public" value="1" class="evt-check-input" @checked($showPublic === '1')>
                            <span>
                                <strong>Yes, show them</strong>
                                <span class="evt-product-choice-hint">Buyers see your name, number and email in the Contact &amp; Help card on the ticket page.</span>
                            </span>
                        </label>
                        <label class="evt-product-choice-card">
                            <input type="radio" name="organizer_details_public" value="0" class="evt-check-input" @checked($showPublic === '0')>
                            <span>
                                <strong>No, keep them private</strong>
                                <span class="evt-product-choice-hint">Only EventHost support is shown to buyers. We still keep these details to reach you.</span>
                            </span>
                        </label>
                    </div>
                    @error('organizer_details_public')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Payout account</h2>
                <p>The bank account EventHost pays your ticket revenue into. Buyers never see this.</p>
            </div>
            <div class="evt-section-body profile-fields">
                @if ($payoutLockedForAdmin)
                    <p class="evt-muted">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        Payout account details are always entered by the account owner. They can add them from their own account once you have saved this page.
                        @if ($event->hasPayoutAccount())
                            A payout account is already on file.
                        @endif
                    </p>
                @else
                    <div class="evt-grid-2">
                        <div class="profile-field">
                            <label for="payout_account_name" class="profile-label">Account holder name</label>
                            <input id="payout_account_name" name="payout_account_name" type="text" required maxlength="120" autocomplete="off"
                                   class="profile-input {{ $errors->has('payout_account_name') ? 'profile-input--error' : '' }}"
                                   value="{{ old('payout_account_name', $event->payout_account_name) }}"
                                   placeholder="Name exactly as on the account">
                            @error('payout_account_name')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                        <div class="profile-field">
                            <label for="payout_account_number" class="profile-label">Account number</label>
                            <input id="payout_account_number" name="payout_account_number" type="text" required inputmode="numeric" maxlength="30" autocomplete="off"
                                   class="profile-input {{ $errors->has('payout_account_number') ? 'profile-input--error' : '' }}"
                                   value="{{ old('payout_account_number', $event->payout_account_number) }}"
                                   placeholder="e.g. 0123456789012">
                            @error('payout_account_number')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    <div class="evt-grid-2">
                        <div class="profile-field">
                            <label for="payout_bank" class="profile-label">Bank</label>
                            <select id="payout_bank" name="payout_bank" required data-cs data-cs-search="auto" data-cs-icon="fa-solid fa-building-columns"
                                    data-cs-placeholder="Choose your bank"
                                    class="profile-input {{ $errors->has('payout_bank') ? 'profile-input--error' : '' }}">
                                <option value="">Choose your bank</option>
                                @foreach ($banks as $bank)
                                    <option value="{{ $bank }}" @selected(old('payout_bank', $event->payout_bank) === $bank)>{{ $bank }}</option>
                                @endforeach
                            </select>
                            @error('payout_bank')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                        <div class="profile-field">
                            <label for="payout_branch" class="profile-label">Branch</label>
                            <input id="payout_branch" name="payout_branch" type="text" required maxlength="120" autocomplete="off"
                                   class="profile-input {{ $errors->has('payout_branch') ? 'profile-input--error' : '' }}"
                                   value="{{ old('payout_branch', $event->payout_branch) }}"
                                   placeholder="e.g. Cairo Road">
                            @error('payout_branch')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="evt-section-body evt-actions-bar">
            @if ($setupMode)
                <button type="submit" class="btn-primary">
                    Save &amp; continue to review <i class="fa-solid fa-arrow-right"></i>
                </button>
                <span class="evt-muted">Next you will review everything and submit for activation.</span>
            @else
                <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save organizer details</button>
            @endif
        </div>
    </form>
</x-app-layout>
