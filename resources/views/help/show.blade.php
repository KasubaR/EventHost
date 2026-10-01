<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
        <script src="{{ asset('js/admin-confirm.js') }}" defer></script>
    @endpush

    <x-slot name="title">Get help</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Get help from our team</h1>
                <p class="dph-sub">Ask us to set up or fix an event for you.</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> My events</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'help-request-sent')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Request sent. We will pick it up as soon as we can.</div>
    @elseif (session('status') === 'help-request-cancelled')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Request cancelled. Our team no longer has access to your account.</div>
    @endif
    @if (session('error'))
        <div class="evt-flash evt-flash--warn" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> {{ session('error') }}</div>
    @endif

    <div class="evt-stack">
        @if ($current)
            <article class="evt-section">
                <div class="evt-section-head">
                    <h2>Your request</h2>
                    <p>{{ $current->kind->label() }}@if ($current->event) &middot; {{ $current->event->name }}@endif</p>
                </div>
                <div class="evt-section-body">
                    <p><strong>{{ $current->status->label() }}</strong>
                        @if ($current->assignedAdmin) &middot; handled by {{ $current->assignedAdmin->name }}@endif
                    </p>
                    <p class="evt-muted">{{ $current->message }}</p>

                    @if ($current->status === \App\Enums\HelpRequestStatus::InProgress)
                        <p class="evt-muted">
                            Our team can work on your account until
                            {{ $current->access_expires_at->timezone(config('events.timezone'))->format('j M Y, H:i') }}.
                            We never change your password or email address, and payments stay yours to make.
                        </p>
                    @else
                        <p class="evt-muted">Nobody from our team can open your account until a request is picked up.</p>
                    @endif

                    <form method="post" action="{{ route('help-request.destroy', $current) }}"
                          data-confirm="Cancel this request? Our team will lose access to your account straight away.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="evt-btn-danger-outline evt-btn-tiny"><i class="fa-solid fa-ban"></i> Cancel request</button>
                    </form>
                </div>
            </article>
        @else
            <article class="evt-section">
                <div class="evt-section-head">
                    <h2>What do you need?</h2>
                    <p>Our team will only open your account after you send this, only for as long as it is open, and you can cancel it at any time.</p>
                </div>
                <div class="evt-section-body">
                    @if ($errors->any())
                        <div class="evt-flash evt-flash--warn" role="alert">
                            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <form method="post" action="{{ route('help-request.store') }}" class="profile-form-stack">
                        @csrf

                        <div class="profile-field">
                            <label for="hr-kind" class="profile-label">Request</label>
                            <select id="hr-kind" name="kind" class="profile-input" data-cs data-cs-search="never">
                                @foreach ($kinds as $kind)
                                    <option value="{{ $kind->value }}" @selected(old('kind', $selectedKind) === $kind->value)>{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="profile-field">
                            <label for="hr-event" class="profile-label">Which event? <span class="evt-muted">(only for "Help with an existing event")</span></label>
                            <select id="hr-event" name="event_id" class="profile-input" data-cs data-cs-search="auto" data-cs-placeholder="Choose an event">
                                <option value="">No specific event</option>
                                @foreach ($events as $event)
                                    <option value="{{ $event->id }}" @selected((int) old('event_id', $selectedEventId) === $event->id)>{{ $event->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="profile-field">
                            <label for="hr-message" class="profile-label">What should we do?</label>
                            <textarea id="hr-message" name="message" rows="5" minlength="10" maxlength="2000" required
                                      class="profile-input" placeholder="For example: a wedding invitation for 12 June, 150 guests, gold and ivory.">{{ old('message') }}</textarea>
                        </div>

                        <div class="profile-field">
                            <label for="hr-contact" class="profile-label">Best way to reach you <span class="evt-muted">(optional)</span></label>
                            <input id="hr-contact" name="contact_preference" type="text" maxlength="255" class="profile-input"
                                   value="{{ old('contact_preference') }}" placeholder="WhatsApp on 097…, weekday evenings">
                        </div>

                        <div class="profile-actions">
                            <button type="submit" class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Send request</button>
                        </div>
                    </form>
                </div>
            </article>
        @endif

        @if ($activity->isNotEmpty())
            <article class="evt-section">
                <div class="evt-section-head">
                    <h2>What our team did</h2>
                    <p>Everything done on your account while we were helping, newest first.</p>
                </div>
                <div class="evt-section-body">
                    @foreach ($activity as $entry)
                        <p>
                            {{ $entry->created_at->timezone(config('events.timezone'))->format('j M Y, H:i') }} &middot;
                            {{ $entry->admin?->name ?? 'Our team' }} &middot; {{ $entry->label() }}@if ($entry->event) &middot; {{ $entry->event->name }}@endif
                        </p>
                    @endforeach
                </div>
            </article>
        @endif

        @if ($past->isNotEmpty())
            <article class="evt-section">
                <div class="evt-section-head"><h2>Earlier requests</h2></div>
                <div class="evt-section-body">
                    @foreach ($past as $earlier)
                        <p>
                            {{ $earlier->created_at->format('j M Y') }} &middot; {{ $earlier->kind->label() }}@if ($earlier->event) &middot; {{ $earlier->event->name }}@endif
                            &middot; <strong>{{ $earlier->status->label() }}</strong>
                        </p>
                    @endforeach
                </div>
            </article>
        @endif
    </div>
</x-app-layout>
