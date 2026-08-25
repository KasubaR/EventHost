<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/events-form.js') }}" defer></script>
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
    @endpush

    <x-slot name="title">Create ticketed event</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.ticketing.index') }}">Ticketing</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>Create</span>
                </nav>
                <h1 class="dph-title">Create ticketed event</h1>
                <p class="dph-sub">Assign the event to a client account. You can add ticket types and activate sales from the review page — no event credit.</p>
            </div>
            <a href="{{ route('admin.ticketing.index') }}" class="evt-btn-outline">Back to queue</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('admin.ticketing.store') }}" class="profile-form">
        @csrf
        <input type="hidden" name="product_kind" value="{{ $productKind->value }}">

        <div class="admin-panel-card">
            <h2>Client</h2>
            <p class="admin-muted">The event will appear on this user’s dashboard. Suspended accounts cannot be selected.</p>
            <div class="profile-field admin-mt-md">
                <label for="user_id" class="profile-label">Organizer account</label>
                <select id="user_id" name="user_id" required data-cs data-cs-search="always"
                        data-cs-placeholder="Search by name or email"
                        data-cs-icon="fa-solid fa-user"
                        class="profile-input {{ $errors->has('user_id') ? 'profile-input--error' : '' }}">
                    <option value="">Select a client…</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) old('user_id', $preselectedUserId) === $client->id)>
                            {{ $client->name }} — {{ $client->email }}
                        </option>
                    @endforeach
                </select>
                @error('user_id')
                    <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="admin-mt-lg">
            @include('events.partials.form-fields', [
                'event' => null,
                'selectedProductKind' => $productKind,
                'kindChangeUrl' => null,
            ])
        </div>

        <div class="evt-section-body evt-actions-bar">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Create draft
            </button>
            <span class="evt-muted">Next: add ticket types, upload a hero, then activate sales.</span>
        </div>
    </form>
</x-admin-layout>
