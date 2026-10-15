<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Share on WhatsApp | {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Share on WhatsApp</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Guests</a>
            </div>
        </div>
    </x-slot>

    @php $total = count($reachable); @endphp

    <div class="evt-stack">
        @if ($blockedReason)
            <div class="evt-admin-flash evt-admin-flash--error" role="alert">{{ $blockedReason }}</div>
        @endif

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>
                    <span data-wa-queue-opened>{{ $openedCount }}</span> of {{ $total }} {{ Str::plural('chat', $total) }} opened
                </h2>
                <p>WhatsApp opens one chat at a time with the invitation already typed. Press send in WhatsApp, come back here and open the next one. A guest is marked sent when you open their chat.</p>
            </div>
            <div class="evt-section-body">
                @if ($total === 0)
                    <p class="evt-muted">None of the selected guests can be reached through WhatsApp.</p>
                @else
                    <ul class="evt-waq-list">
                        @foreach ($reachable as $guest)
                            @php $opened = isset($openedSinceStart[$guest->id]); @endphp
                            <li class="evt-waq-row{{ $opened ? ' is-opened' : '' }}" data-wa-queue-row data-opened="{{ $opened ? '1' : '0' }}">
                                <div class="evt-waq-who">
                                    <span class="evt-waq-name" dir="auto">{{ $guest->name }}</span>
                                    <span class="evt-waq-phone" dir="ltr">{{ \App\Support\GuestPhone::display($guest->phone) }}</span>
                                </div>
                                <span class="evt-pill {{ $opened ? 'evt-pill--accepted' : 'evt-pill--pending' }}" data-wa-queue-status>{{ $opened ? 'Opened' : 'Not opened' }}</span>
                                @if ($blockedReason)
                                    <span class="evt-btn-outline evt-btn-tiny evt-more-item--disabled" aria-disabled="true">
                                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Open WhatsApp
                                    </span>
                                @else
                                    <a href="{{ route('events.guests.whatsapp-share', ['event' => $event, 'guest' => $guest->id]) }}"
                                       class="evt-btn-outline evt-btn-tiny evt-waq-open" target="_blank" rel="noopener noreferrer" data-wa-queue-open>
                                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                        <span data-wa-queue-label>{{ $opened ? 'Open again' : 'Open WhatsApp' }}</span>
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="evt-waq-refresh"><a href="{{ route('events.guests.whatsapp-queue', $event) }}">Refresh</a> to see chats opened from another tab or device.</p>
                @endif
            </div>
        </div>

        @if ($unreachable !== [])
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>{{ count($unreachable) }} {{ Str::plural('guest', count($unreachable)) }} WhatsApp cannot reach</h2>
                    <p>Copy their link or invitation message from the guest list and send it another way, or edit the guest to add a mobile number.</p>
                </div>
                <div class="evt-section-body">
                    <ul class="evt-waq-list">
                        @foreach ($unreachable as $item)
                            <li class="evt-waq-row">
                                <div class="evt-waq-who">
                                    <span class="evt-waq-name" dir="auto">{{ $item['guest']->name }}</span>
                                    <span class="evt-waq-reason">{{ $item['reason'] }}</span>
                                </div>
                                <a href="{{ route('events.guests.edit', ['event' => $event, 'guest' => $item['guest']->id]) }}" class="evt-btn-outline evt-btn-tiny">Edit</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>

    @push('scripts')
        <script src="{{ asset('js/whatsapp-share-queue.js') }}" defer></script>
    @endpush
</x-app-layout>
