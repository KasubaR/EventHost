<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/tables-admin.css') }}">
    @endpush

    <x-slot name="title">Tables & Photo Wall — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Tables & Photo Wall</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                @if ($tables->isNotEmpty())
                    <a href="{{ route('events.tables.qr-sheet', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-print"></i> Print all QR codes</a>
                @endif
                <a href="{{ route('events.checkin.scan', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-qrcode"></i> Check-in scanner</a>
                <a href="{{ route('events.photos.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-images"></i> Photo wall</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'table-created')
        <div class="evt-admin-flash">Table added.</div>
    @elseif (session('status') === 'table-updated')
        <div class="evt-admin-flash">Table updated.</div>
    @elseif (session('status') === 'table-deleted')
        <div class="evt-admin-flash">Table removed.</div>
    @elseif (session('status') === 'event-updated')
        <div class="evt-admin-flash">Photo wall settings saved.</div>
    @endif

    <div class="evt-stack">
        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Photo wall settings</h2>
                <p>Guests scan a table's QR code with their own phone — no login — and their photo lands in the live event gallery.</p>
            </div>
            <div class="evt-section-body">
                <form method="post" action="{{ route('events.update', $event) }}" class="tbl-settings-form">
                    @csrf
                    @method('PATCH')
                    <div class="tbl-settings-row">
                        <input type="hidden" name="photo_wall_enabled" value="0">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="photo_wall_enabled" value="1" class="profile-input evt-check-input" @checked($event->photo_wall_enabled)>
                            <span>Photo wall is on for this event</span>
                        </label>
                    </div>
                    <div class="tbl-settings-row">
                        <input type="hidden" name="photo_wall_requires_approval" value="0">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="photo_wall_requires_approval" value="1" class="profile-input evt-check-input" @checked($event->photo_wall_requires_approval)>
                            <span>Review photos before they appear in the gallery</span>
                        </label>
                    </div>
                    <button type="submit" class="evt-btn-outline evt-btn-tiny">Save settings</button>
                </form>

                @if (! $event->is_public)
                    <p class="evt-flash evt-flash--warn">This event isn't public, so the gallery and table uploads won't go live until "Public event" is enabled on the event's edit page.</p>
                @endif

                <p class="evt-muted">
                    Public gallery: <a href="{{ route('event.gallery.show', $event->slug) }}" class="evt-public-url">{{ url('/e/'.$event->slug.'/gallery') }}</a>
                </p>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Add a table</h2>
                <p>Give it a name — "Table 5", "Bar", "Photo Booth" — then print its QR code for guests to scan.</p>
            </div>
            <div class="evt-section-body">
                <form method="post" action="{{ route('events.tables.store', $event) }}" class="tbl-add-form">
                    @csrf
                    <label class="evt-sr-only" for="table_label">Table label</label>
                    <input id="table_label" type="text" name="label" class="profile-input" placeholder="e.g. Table 5" maxlength="100" required>
                    <button type="submit" class="btn-primary">Add table</button>
                </form>
                @error('label')
                    <p class="profile-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-body">
                @if ($tables->isEmpty())
                    <p class="evt-muted">No tables yet — add one above to generate its QR code.</p>
                @else
                    <div class="tbl-grid">
                        @foreach ($tables as $table)
                            <div class="tbl-card">
                                <img src="{{ route('events.tables.qr', ['event' => $event, 'table' => $table]) }}" alt="QR code for {{ $table->label }}" class="tbl-card-qr" width="160" height="160" loading="lazy">

                                <form method="post" action="{{ route('events.tables.update', ['event' => $event, 'table' => $table]) }}" class="tbl-card-rename">
                                    @csrf
                                    @method('PATCH')
                                    <label class="evt-sr-only" for="table_label_{{ $table->id }}">Label for table {{ $table->id }}</label>
                                    <input id="table_label_{{ $table->id }}" type="text" name="label" value="{{ $table->label }}" class="profile-input evt-btn-tiny" maxlength="100" required>
                                    <button type="submit" class="evt-btn-outline evt-btn-tiny">Save</button>
                                </form>

                                <p class="evt-muted tbl-card-meta">{{ $table->photos_count }} {{ \Illuminate\Support\Str::plural('photo', $table->photos_count) }}</p>

                                <div class="evt-copy-row">
                                    <button type="button" class="evt-icon-btn" data-copy-text="{{ $table->publicUploadUrl() }}" data-copy-label="Copy link" title="Copy upload link">Copy link</button>
                                    <a href="{{ route('events.tables.qr', ['event' => $event, 'table' => $table]) }}" download="table-{{ $table->code }}-qr.svg" class="evt-icon-btn" title="Download QR">Download</a>
                                </div>

                                <form method="post" action="{{ route('events.tables.destroy', ['event' => $event, 'table' => $table]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Remove this table? Its QR code will stop working.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Remove table</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="{{ asset('js/tables-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
