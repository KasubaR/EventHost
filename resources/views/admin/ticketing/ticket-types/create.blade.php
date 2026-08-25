<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
        <script src="{{ asset('js/events-form.js') }}" defer></script>
    @endpush

    @php
        $ev = $adminEvent;
    @endphp

    <x-slot name="title">Add ticket type — {{ $ev->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.ticketing.index') }}">Ticketing</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <a href="{{ route('admin.ticketing.show', $ev) }}">{{ $ev->name }}</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>Add ticket type</span>
                </nav>
                <h1 class="dph-title">Add ticket type</h1>
                <p class="dph-sub">{{ $ev->name }} · {{ $ev->user?->email }}</p>
            </div>
            <a href="{{ route('admin.ticketing.show', $ev) }}" class="evt-btn-outline">Back to review</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('admin.ticketing.ticket-types.store', $ev) }}" enctype="multipart/form-data" class="profile-form">
        @csrf
        <div class="admin-panel-card">
            @include('events.tickets.partials.form', ['event' => $ev, 'ticketType' => null])
        </div>
        <div class="evt-section-body evt-actions-bar">
            <button type="submit" class="btn-primary"><i class="fa-solid fa-plus"></i> Add ticket type</button>
        </div>
    </form>
</x-admin-layout>
