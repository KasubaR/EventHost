@extends('layouts.site')

@section('title', 'Report music | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/contact.css') }}">
@endpush

@section('content')

<section class="contact-hero">
    <div class="contact-hero-inner">
        <h1>Report music on an invitation</h1>
        <p>If this invitation plays music you own and did not allow, tell us. We review reports promptly and remove audio that infringes copyright.</p>
    </div>
</section>

<section class="contact-main">
    <div class="contact-inner">
        <div class="contact-form-wrap">
            @if (session('reported'))
                <div class="contact-success">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <div>
                        <strong>Report received</strong>
                        <p>Thank you. Our team will review it and may contact you at the email you gave.</p>
                    </div>
                </div>
            @else
                <form action="{{ route('audio-report.store', $event) }}" method="POST" class="contact-form" novalidate>
                    @csrf
                    <p class="contact-info-sub">Invitation: <strong>{{ $event->name }}</strong></p>
                    <div class="contact-form-row">
                        <div class="contact-field">
                            <label for="ar_name">Your name <span aria-hidden="true">*</span></label>
                            <input type="text" id="ar_name" name="name" value="{{ old('name') }}" required autocomplete="name"
                                   class="{{ $errors->has('name') ? 'is-error' : '' }}">
                            @error('name')<span class="contact-field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="contact-field">
                            <label for="ar_email">Email address <span aria-hidden="true">*</span></label>
                            <input type="email" id="ar_email" name="email" value="{{ old('email') }}" required autocomplete="email"
                                   class="{{ $errors->has('email') ? 'is-error' : '' }}">
                            @error('email')<span class="contact-field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="contact-field">
                        <label for="ar_holder">Who owns the music? <span class="contact-optional">(optional)</span></label>
                        <input type="text" id="ar_holder" name="rights_holder" value="{{ old('rights_holder') }}" maxlength="150"
                               placeholder="Artist, label or publisher">
                        @error('rights_holder')<span class="contact-field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="contact-field">
                        <label for="ar_details">Details <span aria-hidden="true">*</span></label>
                        <textarea id="ar_details" name="details" rows="6" required
                                  placeholder="Which song is it, and why do you believe it is not licensed for this use?"
                                  class="{{ $errors->has('details') ? 'is-error' : '' }}">{{ old('details') }}</textarea>
                        @error('details')<span class="contact-field-error">{{ $message }}</span>@enderror
                    </div>
                    <button type="submit" class="contact-submit">
                        <i class="fa-solid fa-flag" aria-hidden="true"></i> Send report
                    </button>
                </form>
            @endif
        </div>
    </div>
</section>

@endsection
