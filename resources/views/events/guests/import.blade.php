<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/forms-app.css') }}">
    @endpush

    <x-slot name="title">Import guests — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Import guests</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.import.template', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-download"></i> Download CSV template</a>
                <a href="{{ route('events.guests.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Guests</a>
            </div>
        </div>
    </x-slot>

    <div class="evt-stack">
        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Upload spreadsheet</h2>
                <p>Required column: <strong>name</strong>. Optional: email, phone, group (creates groups automatically).</p>
            </div>
            <div class="evt-section-body profile-card-like">
                <form method="post" action="{{ route('events.guests.import.store', $event) }}" enctype="multipart/form-data" class="profile-form-stack">
                    @csrf
                    <div class="profile-field">
                        <label for="guest_import_file" class="profile-label">CSV or Excel file</label>
                        <input id="guest_import_file" type="file" name="file" class="profile-input {{ $errors->has('file') ? 'profile-input--error' : '' }}" accept=".csv,.txt,.xlsx,.xls" required>
                        @error('file')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <p class="evt-muted">Duplicates are skipped when the same email exists for this event, or when another guest shares the same phone number.</p>
                    <div class="profile-actions">
                        <button type="submit" class="btn-primary">Import guests</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
