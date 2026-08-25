<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
        <script src="{{ asset('js/events-form.js') }}" defer></script>
    @endpush

    <x-slot name="title">Add ticket type — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Add ticket type</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <a href="{{ route('events.ticket-types.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> All tickets</a>
        </div>
    </x-slot>

    <form method="post" action="{{ route('events.ticket-types.store', $event) }}" enctype="multipart/form-data" class="profile-form">
        @csrf
        <div class="evt-section">
            <div class="evt-section-body">
                @include('events.tickets.partials.form', ['event' => $event, 'ticketType' => null])
            </div>
        </div>
        <div class="evt-section-body evt-actions-bar">
            <button type="submit" class="btn-primary"><i class="fa-solid fa-plus"></i> Add ticket type</button>
        </div>
    </form>
</x-app-layout>
