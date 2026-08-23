@extends('layouts.site', ['hideSiteHeader' => true])

@section('title', 'Accept staff invite | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
@endpush

@section('content')

<section class="auth-hero">
  <div class="auth-hero-inner">
    <div class="auth-hero-content">
      <h1 class="auth-hero-headline">
        You've been invited to<br>
        <span class="auth-hero-accent">{{ $eventStaff->event->name }}</span>
      </h1>
      <p class="auth-hero-sub">
        {{ $eventStaff->inviter->name ?? 'The host' }} added you as <strong>{{ $eventStaff->role->label() }}</strong> — {{ $eventStaff->role->description() }}
        Set a password below to create your account and get straight to it.
      </p>
    </div>
  </div>
</section>

<section class="auth-form-section">
  <div class="auth-form-wrap">
    <div class="auth-form-card">
      <div class="auth-card-header">
        <h2>Create your account</h2>
        <p>Already have an account with a different email? <a href="{{ route('login') }}">Sign in</a> instead, then ask the host to re-invite that address.</p>
      </div>

      <form method="POST" action="{{ route('staff-invitations.store', $eventStaff->invite_token) }}">
        @csrf

        <div class="auth-fields">
          <div class="auth-field">
            <label for="email" class="auth-label"><i class="fa-solid fa-envelope"></i> Email address</label>
            <input id="email" type="email" value="{{ $eventStaff->email }}" class="auth-input" disabled>
          </div>

          <div class="auth-field">
            <label for="name" class="auth-label"><i class="fa-solid fa-user"></i> Full name</label>
            <input id="name" type="text" name="name" value="{{ old('name', $eventStaff->name) }}"
                   class="auth-input {{ $errors->has('name') ? 'auth-input--error' : '' }}"
                   placeholder="Your full name" required autofocus autocomplete="name">
            @error('name')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

          <div class="auth-field">
            <label for="password" class="auth-label"><i class="fa-solid fa-lock"></i> Password</label>
            <input id="password" type="password" name="password"
                   class="auth-input {{ $errors->has('password') ? 'auth-input--error' : '' }}"
                   placeholder="Min. 8 characters" required autocomplete="new-password">
            @error('password')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

          <div class="auth-field">
            <label for="password_confirmation" class="auth-label"><i class="fa-solid fa-lock"></i> Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="auth-input {{ $errors->has('password_confirmation') ? 'auth-input--error' : '' }}"
                   placeholder="Repeat your password" required autocomplete="new-password">
            @error('password_confirmation')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>
        </div>

        <button type="submit" class="auth-submit">
          <i class="fa-solid fa-user-plus"></i> Create account & accept invite
        </button>
      </form>
    </div>
  </div>
</section>

@endsection
