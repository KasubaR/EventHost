@php
    $layoutVariant = $invitation['layout_variant'] ?? \App\Support\InvitationLayoutVariant::STANDARD;
    $variantPartial = 'events.invitations.layouts.'.$layoutVariant.'.sections.'.$section['type'];
    $defaultPartial = 'events.invitations.sections.'.$section['type'];
@endphp

@switch($section['type'])
    @case('hero')
        @includeFirst([$variantPartial, $defaultPartial])
        @break

    @case('details')
        @includeFirst([$variantPartial, $defaultPartial])
        @break

    @case('description')
        @includeFirst([$variantPartial, $defaultPartial])
        @break

    @case('story')
        @includeFirst([$variantPartial, $defaultPartial])
        @break

    @case('schedule')
        @includeFirst([$variantPartial, $defaultPartial])
        @break

    @case('rsvp')
        {{-- Ticketed events show "Buy tickets" instead of an RSVP form,
             uniformly across every layout variant — see
             events/invitations/sections/tickets-cta.blade.php. --}}
        @if (isset($event) && $event->isTicketed())
            @include('events.invitations.sections.tickets-cta')
        @else
            @includeFirst([$variantPartial, $defaultPartial])
        @endif
        @break

    @case('countdown')
        @includeFirst([$variantPartial, $defaultPartial])
        @break

    @case('gallery')
        @includeFirst([$variantPartial, $defaultPartial])
        @break
@endswitch
