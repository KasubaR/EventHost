@php
    $associateForm = $associateForm ?? false;
    $hidden = $hidden ?? false;
    $heading = $heading ?? 'Cover Image';
    $description = $description ?? 'Recommended wide image; we crop to 1200×630 for sharing.';
@endphp
<div class="evt-section" data-product-panel="invitation" @if ($hidden) hidden @endif>
    <div class="evt-section-head">
        <h2>{{ $heading }}</h2>
        <p>{{ $description }}</p>
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
                       @if ($associateForm) form="event-update-form" @endif
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
