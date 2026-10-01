@php
    $acting = app(\App\Services\ActingAsService::class);
    $actingAdmin = $acting->isActive() ? $acting->admin() : null;
    $actingClient = $actingAdmin ? auth('web')->user() : null;
@endphp

@if ($actingAdmin && $actingClient)
    @once
        <link rel="stylesheet" href="{{ asset('css/acting-as.css') }}">
    @endonce

    @if (session('acting_notice'))
        <div class="acting-as-notice" role="alert">{{ session('acting_notice') }}</div>
    @endif

    {{-- Fixed to the bottom edge rather than the top, so it never pushes the sidebar or
         site header around. Always visible, on every page that uses a layout. --}}
    <div class="acting-as-bar" role="status">
        <div class="acting-as-bar__text">
            <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
            <span>
                You are acting as <strong>{{ $actingClient->name }}</strong>
                <span class="acting-as-bar__meta">({{ $actingClient->email }}) &middot; signed in as {{ $actingAdmin->name }}
                    &middot; ends in {{ $acting->minutesRemaining() }} min</span>
            </span>
        </div>
        <form method="POST" action="{{ route('admin.acting-as.destroy') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="acting-as-bar__exit">Exit</button>
        </form>
    </div>
    <script>document.body.classList.add('has-acting-as-bar');</script>
@endif
