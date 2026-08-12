@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/event-gallery.css') }}">
@endpush

@section('title', 'Photo gallery | '.$event->name)

@section('content')
    <article class="egal-page">
        <header class="egal-header">
            <p class="rsvp-event-badge"><i class="fa-solid fa-images" aria-hidden="true"></i> Live gallery</p>
            <h1 class="rsvp-title">{{ $event->name }}</h1>
            <p class="rsvp-greeting">Photos guests are sharing from tonight.</p>
        </header>

        @if (! $isLive)
            <p class="egal-empty">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                The photo wall isn't turned on for this event right now.
            </p>
        @else
            <div class="egal-grid" id="egalGrid" data-egal-grid data-feed-url="{{ route('event.gallery.feed', $event->slug) }}">
                @foreach ($photos as $photo)
                    <figure class="egal-item" data-photo-id="{{ $photo->id }}">
                        <img src="{{ $photo->thumbnail_url }}" alt="Photo from {{ $photo->uploader_name ?? 'a guest' }}" loading="lazy">
                        @if ($photo->uploader_name)
                            <figcaption>{{ $photo->uploader_name }}</figcaption>
                        @endif
                    </figure>
                @endforeach
            </div>

            @if ($photos->isEmpty())
                <p class="egal-empty" id="egalEmptyState">No photos yet — be the first to add one from your table's QR code.</p>
            @endif
        @endif
    </article>
@endsection

@push('scripts')
    <script src="{{ asset('js/event-gallery.js') }}" defer></script>
@endpush
