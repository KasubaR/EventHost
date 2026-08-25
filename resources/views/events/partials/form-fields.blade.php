@push('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
@endpush

@php
    $timeRaw = old('event_time', isset($event) ? $event?->event_time : '12:00:00');
    $timeForInput = is_string($timeRaw) && strlen($timeRaw) >= 5 ? substr($timeRaw, 0, 5) : '12:00';

    $rsvpOld = old('rsvp_deadline');
    $rsvpValue = $rsvpOld !== null
        ? $rsvpOld
        : (isset($event) && $event?->rsvp_deadline ? $event->rsvp_deadline->format('Y-m-d\TH:i') : '');

    $selectedProductKind = $selectedProductKind ?? null;
    $productKind = old(
        'product_kind',
        $event?->product_kind?->value ?? ($selectedProductKind?->value ?? \App\Enums\EventProductKind::Invitation->value)
    );
    $isTicketed = $productKind === \App\Enums\EventProductKind::Ticketed->value;
    $productKindLocked = $event !== null || $selectedProductKind instanceof \App\Enums\EventProductKind;

    // Options differ by product kind — ticketed events get commercial types
    // (Concert, Conference, ...) instead of the invitation list (Wedding,
    // Baby Shower, ...). Grandfathers the event's own current value in if
    // it's not in that kind's canonical set. See Event::eventTypesFor().
    $eventTypeOptions = \App\Models\Event::eventTypesFor(
        \App\Enums\EventProductKind::from($productKind),
        $event?->event_type
    );
@endphp

@unless ($isTicketed)
    <input type="hidden" name="is_public" value="0">
    <input type="hidden" name="allow_plus_one" value="0">
    <input type="hidden" name="show_guest_list" value="0">
@endunless

<div class="evt-stack">

    <div class="evt-section">
        <div class="evt-section-head">
            <h2>Event Details</h2>
            <p>Name, type, and description.</p>
        </div>
        <div class="evt-section-body profile-fields">
            <div class="profile-field">
                <label for="name" class="profile-label">Event name</label>
                <input id="name" name="name" type="text" required maxlength="255"
                       class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}"
                       value="{{ old('name', $event?->name ?? '') }}">
                @error('name')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
            </div>

            <div class="profile-field">
                <label for="event_type" class="profile-label">Event type</label>
                <select id="event_type" name="event_type" required data-cs data-cs-icon="fa-solid fa-tag"
                        class="profile-input {{ $errors->has('event_type') ? 'profile-input--error' : '' }}">
                    @foreach($eventTypeOptions as $type)
                        <option value="{{ $type }}" @selected(old('event_type', $event?->event_type ?? $eventTypeOptions[0]) === $type)>
                            {{ \App\Models\Event::TYPE_LABELS[$type] ?? $type }}
                        </option>
                    @endforeach
                </select>
                @error('event_type')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
            </div>

            <div class="profile-field">
                <span class="profile-label">How people join</span>
                <p class="evt-readonly-note">
                    {{ $event?->product_kind?->label() ?? \App\Enums\EventProductKind::from($productKind)->label() }}
                    @if ($event)
                        — this is set when the event is created and cannot be changed.
                    @elseif (! empty($kindChangeUrl))
                        <a href="{{ $kindChangeUrl }}" class="evt-kind-change">Change</a>
                    @endif
                </p>
            </div>

            <div class="profile-field">
                <label for="description" class="profile-label">Description <span class="profile-optional">optional</span></label>
                <textarea id="description" name="description" rows="4"
                          class="profile-input {{ $errors->has('description') ? 'profile-input--error' : '' }}"
                          placeholder="Tell guests what to expect">{{ old('description', $event?->description ?? '') }}</textarea>
                @error('description')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
            </div>

            <div class="profile-field">
                <label for="slug" class="profile-label">Custom URL <span class="profile-optional">optional</span></label>
                <div class="evt-slug-input">
                    <span class="evt-slug-prefix">{{ rtrim(config('app.url'), '/') }}/e/</span>
                    <input id="slug" name="slug" type="text" maxlength="60"
                           class="profile-input {{ $errors->has('slug') ? 'profile-input--error' : '' }}"
                           value="{{ old('slug', $event?->slug ?? '') }}"
                           placeholder="john-mary"
                           autocomplete="off"
                           spellcheck="false">
                </div>
                <p class="evt-field-hint">Lowercase letters, numbers, and hyphens. Leave blank on create to generate from the event name. Changing it keeps the old link as a redirect.</p>
                @error('slug')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
            </div>
        </div>
    </div>

    <div class="evt-section">
        <div class="evt-section-head">
            <h2>Schedule &amp; Venue</h2>
            <p>When and where you are hosting.</p>
        </div>
        <div class="evt-section-body">
            <div class="evt-grid-2 profile-fields">
                <div class="profile-field">
                    <label for="event_date" class="profile-label">Date</label>
                    <input id="event_date" name="event_date" type="date" required data-dtp
                           data-placeholder="Pick the event date"
                           class="profile-input {{ $errors->has('event_date') ? 'profile-input--error' : '' }}"
                           value="{{ old('event_date', isset($event) ? $event->event_date->format('Y-m-d') : '') }}">
                    @error('event_date')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="profile-field">
                    <label for="event_time" class="profile-label">Time</label>
                    <input id="event_time" name="event_time" type="time" required step="60" data-dtp
                           data-minute-step="5" data-placeholder="Pick a start time"
                           class="profile-input {{ $errors->has('event_time') ? 'profile-input--error' : '' }}"
                           value="{{ $timeForInput }}">
                    @error('event_time')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="profile-fields evt-fields-spaced">
                <div class="profile-field">
                    <label for="venue" class="profile-label">Venue <span class="profile-optional">optional</span></label>
                    <input id="venue" name="venue" type="text" maxlength="255"
                           class="profile-input {{ $errors->has('venue') ? 'profile-input--error' : '' }}"
                           value="{{ old('venue', $event?->venue ?? '') }}">
                    @error('venue')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="profile-field">
                    <label for="location_name" class="profile-label">Location label <span class="profile-optional">optional</span></label>
                    <input id="location_name" name="location_name" type="text" maxlength="255"
                           class="profile-input {{ $errors->has('location_name') ? 'profile-input--error' : '' }}"
                           value="{{ old('location_name', $event?->location_name ?? '') }}"
                           placeholder="City or neighbourhood">
                    @error('location_name')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="profile-field">
                    <label class="profile-label">Location pin <span class="profile-optional">optional</span></label>
                    <div class="evt-map-search">
                        <input type="text" id="evt-map-search" class="profile-input" placeholder="Search address…" autocomplete="off">
                        <button type="button" id="evt-map-search-btn" class="evt-btn-outline">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </button>
                        <button type="button" id="evt-map-locate-btn" class="evt-btn-outline" title="Use my current location">
                            <i class="fa-solid fa-location-crosshairs"></i>
                        </button>
                    </div>
                    <div class="evt-map-wrap">
                        <div id="evt-map" class="evt-map"></div>
                    </div>
                    <p class="evt-map-hint">Click the map or drag the pin to set location. Search or "use my location" auto-fill the pin.</p>
                    <p id="evt-map-status" class="evt-map-status" role="status" aria-live="polite" hidden></p>
                    <div class="evt-grid-2">
                        <div class="profile-field">
                            <label for="latitude" class="profile-label">Latitude</label>
                            <input id="latitude" name="latitude" type="text" inputmode="decimal" readonly
                                   class="profile-input evt-map-coord-input {{ $errors->has('latitude') ? 'profile-input--error' : '' }}"
                                   value="{{ old('latitude', $event?->latitude ?? '') }}"
                                   placeholder="Set via map">
                            @error('latitude')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                        <div class="profile-field">
                            <label for="longitude" class="profile-label">Longitude</label>
                            <input id="longitude" name="longitude" type="text" inputmode="decimal" readonly
                                   class="profile-input evt-map-coord-input {{ $errors->has('longitude') ? 'profile-input--error' : '' }}"
                                   value="{{ old('longitude', $event?->longitude ?? '') }}"
                                   placeholder="Set via map">
                            @error('longitude')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Ticketed events have no host cover. EventHost sets the public hero
         (same 1200×630 crop, stored as cover_image) on the ticketing review
         page. On create the section stays in the DOM so the product-kind
         toggle can hide it; on a locked ticketed edit it is omitted entirely. --}}
    @if (! $productKindLocked || ! $isTicketed)
        <div class="evt-section" data-product-panel="invitation" @if ($isTicketed) hidden @endif>
            <div class="evt-section-head">
                <h2>Cover Image</h2>
                <p>Recommended wide image; we crop to 1200×630 for sharing.</p>
            </div>
            <div class="evt-section-body">
                <div class="profile-photo-row">
                    <img src="{{ isset($event) ? $event->cover_image_url : asset('images/default-event.png') }}" alt="" width="120" height="68" class="profile-photo-preview evt-cover-preview" id="evt-cover-preview">
                    <div>
                        <label for="cover_image" class="profile-photo-btn">
                            <i class="fa-solid fa-image"></i> Upload cover
                        </label>
                        {{-- Staged on pick only when the event already exists; the create
                             page has no id to scope an upload to, so it posts the file
                             with the form as it always has. --}}
                        <input id="cover_image" name="cover_image" type="file" accept="image/jpeg,image/png,image/webp" class="profile-photo-input"
                               @isset($event)
                                   data-upload-slot="cover"
                                   data-upload-url="{{ route('events.media.stage', $event) }}"
                                   data-upload-max-bytes="{{ \App\Support\InvitationMediaRules::COVER_MAX_KB * 1024 }}"
                               @endisset>
                        <p class="profile-photo-hint">JPG, PNG or WEBP · Max 4MB</p>
                        @error('cover_image')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="evt-section" data-product-panel="ticketed" @unless ($isTicketed) hidden @endunless>
        <div class="evt-section-head">
            <h2>Ticket Sales</h2>
            <p>EventHost checkout is the only online payment path for this event.</p>
        </div>
        <div class="evt-section-body">
            <p class="evt-muted">After you save this draft you can add ticket types, then submit them for EventHost to activate. Buyers will pay through Lenco — you cannot add an MTN number, bank details, or “pay me on WhatsApp” on the EventHost ticket page.</p>
        </div>
    </div>

    {{-- RSVP / guest-list rules belong to invitation events. Ticketed events
         sell through checkout, so this block is omitted once the product kind
         is locked (create after the chooser, and every edit). --}}
    @if (! $productKindLocked || ! $isTicketed)
        <div class="evt-section" data-product-panel="invitation" @if ($isTicketed) hidden @endif>
            <div class="evt-section-head">
                <h2>Guest Settings</h2>
                <p>RSVP rules and visibility (RSVP form comes later).</p>
            </div>
        <div class="evt-section-body profile-fields">
            <label class="profile-label evt-check-label">
                <input type="checkbox" name="is_public" value="1" class="profile-input evt-check-input"
                       @checked((string) old('is_public', ($event?->is_public ?? true) ? '1' : '0') === '1')>
                Public invitation (listed on our Discover page and shareable by link)
            </label>

            <div class="profile-field">
                <label for="rsvp_deadline" class="profile-label">RSVP deadline <span class="profile-optional">optional</span></label>
                <input id="rsvp_deadline" name="rsvp_deadline" type="datetime-local" data-dtp
                       data-minute-step="15" data-placeholder="No deadline set"
                       class="profile-input {{ $errors->has('rsvp_deadline') ? 'profile-input--error' : '' }}"
                       value="{{ $rsvpValue }}">
                @error('rsvp_deadline')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
            </div>

            <div class="profile-field">
                <span class="profile-label">Guest limit</span>
                <div class="evt-guest-limit-radios">
                    <label class="profile-label evt-check-label">
                        <input type="radio" name="guest_limit_mode" value="open"
                               class="profile-input evt-check-input"
                               id="guest_limit_open">
                        Open to all guests
                    </label>
                    <label class="profile-label evt-check-label">
                        <input type="radio" name="guest_limit_mode" value="set"
                               class="profile-input evt-check-input"
                               id="guest_limit_set">
                        Set a limit
                    </label>
                </div>
                <div id="guest_limit_wrap">
                    <input id="guest_limit" name="guest_limit" type="number" min="1" step="1"
                           class="profile-input {{ $errors->has('guest_limit') ? 'profile-input--error' : '' }}"
                           value="{{ old('guest_limit', $event?->guest_limit ?? '') }}"
                           placeholder="e.g. 200">
                    @error('guest_limit')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </div>

            <label class="profile-label evt-check-label">
                <input type="checkbox" name="allow_plus_one" value="1" class="profile-input evt-check-input"
                       @checked((string) old('allow_plus_one', ($event?->allow_plus_one ?? false) ? '1' : '0') === '1')>
                Allow plus-one
            </label>

            <label class="profile-label evt-check-label">
                <input type="checkbox" name="show_guest_list" value="1" class="profile-input evt-check-input"
                       @checked((string) old('show_guest_list', ($event?->show_guest_list ?? false) ? '1' : '0') === '1')>
                Show guest list on invitation
            </label>
        </div>
        </div>
    @endif

</div>
