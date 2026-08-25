@php
    $startsRaw = old('sales_starts_at', $ticketType?->sales_starts_at);
    $endsRaw = old('sales_ends_at', $ticketType?->sales_ends_at);
    $startsValue = $startsRaw instanceof \Illuminate\Support\Carbon ? $startsRaw->format('Y-m-d\TH:i') : (string) ($startsRaw ?? '');
    $endsValue = $endsRaw instanceof \Illuminate\Support\Carbon ? $endsRaw->format('Y-m-d\TH:i') : (string) ($endsRaw ?? '');
@endphp

<div class="profile-fields">
    <div class="profile-field">
        <label for="ticket_name" class="profile-label">Ticket name</label>
        <input id="ticket_name" name="name" type="text" required maxlength="120"
               class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}"
               value="{{ old('name', $ticketType?->name ?? '') }}">
        @error('name')
            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
        @enderror
    </div>

    <div class="profile-field">
        <label for="ticket_badge_color" class="profile-label">Badge color <span class="profile-optional">shown on the ticket PDF</span></label>
        <select id="ticket_badge_color" name="badge_color" required data-cs data-cs-icon="fa-solid fa-tag"
                class="profile-input {{ $errors->has('badge_color') ? 'profile-input--error' : '' }}">
            @foreach (\App\Models\TicketType::BADGE_COLORS as $value => $label)
                <option value="{{ $value }}" @selected(old('badge_color', $ticketType?->badge_color ?? \App\Models\TicketType::DEFAULT_BADGE_COLOR) === $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        @error('badge_color')
            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
        @enderror
    </div>

    <div class="profile-field">
        <label for="ticket_description" class="profile-label">Description <span class="profile-optional">optional</span></label>
        <textarea id="ticket_description" name="description" rows="3"
                  class="profile-input {{ $errors->has('description') ? 'profile-input--error' : '' }}">{{ old('description', $ticketType?->description ?? '') }}</textarea>
        @error('description')
            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
        @enderror
    </div>

    <div class="evt-grid-2">
        <div class="profile-field">
            <label for="ticket_price" class="profile-label">Price (ZMW)</label>
            <input id="ticket_price" name="price" type="number" min="0.01" step="0.01" required
                   class="profile-input {{ $errors->has('price') ? 'profile-input--error' : '' }}"
                   value="{{ old('price', $ticketType?->price ?? '') }}">
            @error('price')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
        <div class="profile-field">
            <label for="ticket_quantity" class="profile-label">Quantity <span class="profile-optional">blank = unlimited</span></label>
            <input id="ticket_quantity" name="quantity" type="number" min="1" step="1"
                   class="profile-input {{ $errors->has('quantity') ? 'profile-input--error' : '' }}"
                   value="{{ old('quantity', $ticketType?->quantity ?? '') }}">
            @error('quantity')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
    </div>

    <div class="evt-grid-2">
        <div class="profile-field">
            <label for="sales_starts_at" class="profile-label">Sales start <span class="profile-optional">optional</span></label>
            <input id="sales_starts_at" name="sales_starts_at" type="datetime-local" data-dtp
                   data-minute-step="15" data-placeholder="Sales open immediately"
                   class="profile-input {{ $errors->has('sales_starts_at') ? 'profile-input--error' : '' }}"
                   value="{{ $startsValue }}">
            @error('sales_starts_at')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
        <div class="profile-field">
            <label for="sales_ends_at" class="profile-label">Sales end <span class="profile-optional">optional</span></label>
            <input id="sales_ends_at" name="sales_ends_at" type="datetime-local" data-dtp
                   data-minute-step="15" data-placeholder="Until the event"
                   class="profile-input {{ $errors->has('sales_ends_at') ? 'profile-input--error' : '' }}"
                   value="{{ $endsValue }}">
            @error('sales_ends_at')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
    </div>

    <div class="evt-grid-2">
        <div class="profile-field">
            <label for="min_per_order" class="profile-label">Minimum per order</label>
            <input id="min_per_order" name="min_per_order" type="number" min="1" max="100" required
                   class="profile-input {{ $errors->has('min_per_order') ? 'profile-input--error' : '' }}"
                   value="{{ old('min_per_order', $ticketType?->min_per_order ?? 1) }}">
            @error('min_per_order')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
        <div class="profile-field">
            <label for="max_per_order" class="profile-label">Maximum per order</label>
            <input id="max_per_order" name="max_per_order" type="number" min="1" max="100" required
                   class="profile-input {{ $errors->has('max_per_order') ? 'profile-input--error' : '' }}"
                   value="{{ old('max_per_order', $ticketType?->max_per_order ?? 10) }}">
            @error('max_per_order')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
    </div>

    <div class="profile-field">
        <label for="ticket_image" class="profile-label">Ticket image <span class="profile-optional">optional</span></label>
        @if ($ticketType?->image_url)
            <img src="{{ $ticketType->image_url }}" alt="" width="120" height="68" class="evt-cover-preview">
        @endif
        <input id="ticket_image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="profile-input">
        @error('image')
            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
        @enderror
    </div>

    <div class="profile-field">
        <label for="ticket_terms" class="profile-label">Ticket terms <span class="profile-optional">optional</span></label>
        <textarea id="ticket_terms" name="terms" rows="3"
                  class="profile-input {{ $errors->has('terms') ? 'profile-input--error' : '' }}">{{ old('terms', $ticketType?->terms ?? '') }}</textarea>
        @error('terms')
            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
        @enderror
    </div>

    <div class="profile-field">
        <label for="sort_order" class="profile-label">Display order</label>
        <input id="sort_order" name="sort_order" type="number" min="0" step="1"
               class="profile-input {{ $errors->has('sort_order') ? 'profile-input--error' : '' }}"
               value="{{ old('sort_order', $ticketType?->sort_order ?? 0) }}">
        @error('sort_order')
            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
        @enderror
    </div>

    <input type="hidden" name="is_active" value="0">
    <label class="profile-label evt-check-label">
        <input type="checkbox" name="is_active" value="1" class="evt-check-input"
               @checked((string) old('is_active', ($ticketType?->is_active ?? true) ? '1' : '0') === '1')>
        Active (buyers can select this type once sales are live)
    </label>
</div>
