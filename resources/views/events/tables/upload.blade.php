@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/table-upload.css') }}">
@endpush

@section('title', $table->label.' | '.$event->name)

@section('content')
    <article class="rsvp-page tbup-page">
        <div class="rsvp-card tbup-card">
            <header class="rsvp-header">
                <p class="rsvp-event-badge"><i class="fa-solid fa-camera" aria-hidden="true"></i> Photo wall</p>
                <h1 class="rsvp-title">{{ $event->name }}</h1>
                <p class="rsvp-greeting">{{ $table->label }}</p>
                <hr class="rsvp-divider">
            </header>

            @if (! $isLive)
                <p class="tbup-closed">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    The photo wall isn't turned on for this event right now.
                </p>
            @else
                <form id="tbupForm"
                      class="tbup-form"
                      method="post"
                      action="{{ route('table.upload.store', ['slug' => $event->slug, 'code' => $table->code]) }}"
                      enctype="multipart/form-data"
                      data-tbup-form>
                    @csrf

                    <label for="tbupPhoto" class="tbup-drop" id="tbupDrop">
                        <i class="fa-solid fa-camera tbup-drop-icon" aria-hidden="true"></i>
                        <span class="tbup-drop-label">Take, choose, or drag in photos</span>
                        <input id="tbupPhoto" type="file" name="photo" accept="image/*" capture="environment" multiple>
                    </label>
                    <ul class="tbup-queue" id="tbupQueue"></ul>

                    <div class="tbup-field">
                        <label for="tbupName" class="rsvp-field-label">Your name <span class="rsvp-optional">optional</span></label>
                        <input id="tbupName" type="text" name="uploader_name" class="profile-input" maxlength="60" placeholder="So the host knows who to thank">
                    </div>

                    <button type="submit" class="btn-primary tbup-submit" id="tbupSubmit">
                        <i class="fa-solid fa-upload" aria-hidden="true"></i> Add to the photo wall
                    </button>

                    <p class="tbup-status" id="tbupStatus" role="status" aria-live="polite"></p>
                </form>

                <p class="tbup-gallery-link">
                    <a href="{{ route('event.gallery.show', $event->slug) }}">See the shared gallery</a>
                </p>
            @endif
        </div>
    </article>
@endsection

@push('scripts')
    <script src="{{ asset('js/table-upload.js') }}" defer></script>
@endpush
