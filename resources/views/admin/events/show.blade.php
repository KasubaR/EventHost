<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @php
        $ev = $adminEvent;
        $ticketingTone = $ev->isTicketed() ? $ev->ticketing_status->tone() : 'info';
    @endphp

    <x-slot name="title">{{ $ev->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.events.index') }}">Events</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>{{ $ev->name }}</span>
                </nav>
                <h1 class="dph-title">{{ $ev->name }}</h1>
                <p class="dph-sub">Owner: {{ $ev->user?->email ?? '—' }}</p>
            </div>
            <div class="admin-actions">
                <a href="{{ route('admin.events.index') }}" class="evt-btn-outline dash-header-cta">Back</a>
                @if ($ev->is_published && $ev->is_public)
                    <a href="{{ route('events.public', $ev->slug) }}" class="btn-primary dash-header-cta" target="_blank" rel="noopener">Public page</a>
                @endif
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Summary</h2>

            <dl class="admin-fact-grid">
                <div class="admin-fact">
                    <dt>Type</dt>
                    <dd>
                        {{ $ev->event_type_label }}
                        @if ($ev->event_date)
                            <span class="admin-fact-sub">{{ $ev->event_date->format('j M Y') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="admin-fact">
                    <dt>Public RSVP</dt>
                    <dd>{{ $ev->is_public ? 'Allowed' : 'Invite only' }}</dd>
                </div>
                <div class="admin-fact">
                    <dt>Guests</dt>
                    <dd>
                        {{ number_format($ev->guests_count) }}
                        @if(auth('admin')->user()?->can('guests.view'))
                            <a href="{{ route('admin.events.guests', $ev) }}" class="admin-link admin-fact-link">View guests</a>
                        @endif
                    </dd>
                </div>
                <div class="admin-fact">
                    <dt>RSVPs</dt>
                    <dd>
                        {{ number_format($ev->rsvps_count) }}
                        @if(auth('admin')->user()?->can('rsvps.view'))
                            <a href="{{ route('admin.events.rsvps', $ev) }}" class="admin-link admin-fact-link">View RSVPs</a>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($ev->trashed())
                <div class="admin-callout admin-callout--danger admin-mt-md">
                    <div class="admin-callout-icon" aria-hidden="true"><i class="fa-solid fa-trash"></i></div>
                    <div>
                        <p class="admin-callout-kicker">Deleted</p>
                        <p class="admin-callout-body">Guests see “Invitation no longer available”. The slug <code>/e/{{ $ev->slug }}</code> stays reserved.</p>
                        @if(auth('admin')->user()?->can('events.delete'))
                            <div class="admin-callout-actions">
                                <form method="post" action="{{ route('admin.events.restore', $ev) }}">
                                    @csrf
                                    <button type="submit" class="btn-primary"><i class="fa-solid fa-rotate-left"></i> Restore event</button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @elseif ($ev->isTicketed())
                <div class="admin-callout admin-callout--{{ $ticketingTone }}">
                    <div class="admin-callout-icon" aria-hidden="true">
                        <i class="fa-solid {{ $ev->ticketing_status->icon() }}"></i>
                    </div>
                    <div>
                        <p class="admin-callout-kicker">Ticketing</p>
                        <p class="admin-callout-body">Ticketed events go live from the Ticketing queue — they do not use event credits.</p>
                        @if(auth('admin')->user()?->can('ticketing.view'))
                            <div class="admin-callout-actions">
                                <a href="{{ route('admin.ticketing.show', $ev) }}" class="btn-primary">
                                    <i class="fa-solid fa-ticket" aria-hidden="true"></i> Open ticketing review
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
                @if(auth('admin')->user()?->can('events.publish_toggle') && $ev->is_published)
                    <div class="admin-actions admin-mt-md">
                        @include('admin.events.partials.lifecycle-actions', ['ev' => $ev])
                    </div>
                @endif
            @elseif(auth('admin')->user()?->can('events.publish_toggle'))
                @if (! $ev->is_published)
                    <form method="post" action="{{ route('admin.events.publish', $ev) }}" class="profile-form admin-mt-md">
                        @csrf
                        @method('PATCH')
                        <fieldset>
                            <legend class="admin-muted">Draft — first publish</legend>
                            <label class="admin-mt-sm">
                                <input type="hidden" name="is_published" value="0">
                                <input type="checkbox" name="is_published" value="1" @checked($ev->is_published)>
                                Published (live invitation)
                            </label>
                        </fieldset>
                        <div class="admin-actions admin-mt-md">
                            <button type="submit" class="btn-primary">Save publish state</button>
                        </div>
                    </form>
                @else
                    <div class="admin-mt-md">
                        <p class="admin-muted">Live invitation — pause or cancel instead of unpublishing.</p>
                        <div class="admin-actions admin-mt-sm">
                            @include('admin.events.partials.lifecycle-actions', ['ev' => $ev])
                        </div>
                    </div>
                @endif
            @endif

            @if(auth('admin')->user()?->can('events.delete') && ! $ev->trashed())
                <form method="post" action="{{ route('admin.events.destroy', $ev) }}" class="profile-form admin-danger-row" data-confirm="Delete this event? Guests will see that the invitation is no longer available. You can restore it later.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Delete event</button>
                </form>
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Owner account</h2>
            <p class="admin-muted admin-mt-sm">{{ $ev->user?->name ?? '—' }}</p>
            <p class="admin-muted">{{ $ev->user?->phone ?? 'No phone' }} · Status {{ $ev->user?->status ?? '—' }}</p>
            @if(auth('admin')->user()?->can('users.view'))
                @if ($ev->user)
                    <p class="admin-mt-md"><a href="{{ route('admin.users.show', $ev->user) }}" class="admin-link">Open user</a></p>
                @endif
            @endif
        </div>
    </div>
</x-admin-layout>
