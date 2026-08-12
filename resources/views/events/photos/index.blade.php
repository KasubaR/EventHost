<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/tables-admin.css') }}">
    @endpush

    <x-slot name="title">Photo wall — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Photo wall</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('event.gallery.show', $event->slug) }}" class="evt-btn-outline" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-up-right-from-square"></i> View public gallery</a>
                <a href="{{ route('events.tables.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-qrcode"></i> Tables</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'photo-updated')
        <div class="evt-admin-flash">Photo updated.</div>
    @elseif (session('status') === 'photo-deleted')
        <div class="evt-admin-flash">Photo removed.</div>
    @endif

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $stats['total'] }}</div>
                <div class="evt-stat-label">Total photos</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $stats['pending'] }}</div>
                <div class="evt-stat-label">Pending review</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $stats['hidden'] }}</div>
                <div class="evt-stat-label">Hidden</div>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-body">
                @if ($photos->isEmpty())
                    <p class="evt-muted">No photos yet. Print table QR codes from the Tables page so guests can start posting.</p>
                @else
                    <div class="tbl-photo-grid">
                        @foreach ($photos as $photo)
                            <div class="tbl-photo-card">
                                <img src="{{ $photo->thumbnail_url }}" alt="Photo from {{ $photo->uploader_name ?? 'a guest' }}" class="tbl-photo-thumb" loading="lazy">
                                <div class="tbl-photo-meta">
                                    <span class="evt-pill evt-pill--{{ $photo->status->value === 'approved' ? 'accepted' : ($photo->status->value === 'hidden' ? 'declined' : 'pending') }}">{{ $photo->status->label() }}</span>
                                    @if ($photo->table)
                                        <span class="evt-muted">{{ $photo->table->label }}</span>
                                    @endif
                                </div>
                                @if ($photo->uploader_name)
                                    <p class="evt-muted tbl-photo-uploader">{{ $photo->uploader_name }}</p>
                                @endif
                                <div class="tbl-photo-actions">
                                    @if ($photo->status->value !== 'approved')
                                        <form method="post" action="{{ route('events.photos.update', ['event' => $event, 'photo' => $photo]) }}" class="evt-inline-form">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="approved">
                                            <button type="submit" class="evt-btn-outline evt-btn-tiny">Approve</button>
                                        </form>
                                    @endif
                                    @if ($photo->status->value !== 'hidden')
                                        <form method="post" action="{{ route('events.photos.update', ['event' => $event, 'photo' => $photo]) }}" class="evt-inline-form">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="hidden">
                                            <button type="submit" class="evt-btn-outline evt-btn-tiny">Hide</button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('events.photos.destroy', ['event' => $event, 'photo' => $photo]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Delete this photo permanently?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Delete</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{ $photos->links() }}
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="{{ asset('js/tables-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
