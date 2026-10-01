@props(['event' => null])

{{-- A slim "ask our team" prompt, shown only while team help is switched on
     (admin.acting_as.enabled — the same flag the Privacy copy follows). Swaps to a status line
     while the client already has a request open, so it never invites a second one.
     Styles: .help-card in events-admin.css. Plan: plans/admin-create-events.md. --}}
@if (config('admin.acting_as.enabled') && auth()->check())
    @php
        $current = \App\Models\AdminHelpRequest::query()
            ->where('user_id', auth()->id())
            ->current()
            ->latest()
            ->first();

        $link = route('help-request.show', $event ? ['event' => $event->id] : ['kind' => 'create_event']);
    @endphp

    <aside class="help-card" aria-label="Help from our team">
        <img src="{{ asset('images/logo/EventHost Logo_Icon.svg') }}" alt="" class="help-card__logo" width="40" height="40">

        <div class="help-card__text">
            @if ($current)
                <strong>{{ $current->status === \App\Enums\HelpRequestStatus::InProgress ? 'Our team is working on your request' : 'Your request is with our team' }}</strong>
                <span>We will email you when it is done. You can cancel it at any time.</span>
            @elseif ($event)
                <strong>Stuck on this event?</strong>
                <span>Our team can set it up or fix it for you.</span>
            @else
                <strong>Want us to do it for you?</strong>
                <span>Our team can set up your event, so you only have to check it and publish.</span>
            @endif
        </div>

        <a href="{{ $current ? route('help-request.show') : $link }}" class="help-card__link">
            {{ $current ? 'View request' : 'Ask our team' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
    </aside>
@endif
