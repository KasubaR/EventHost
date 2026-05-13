@foreach ($invitation['sections'] as $section)
    @if (! ($section['visible'] ?? true))
        @continue
    @endif

    @php
        $layoutVariant = $invitation['layout_variant'] ?? \App\Support\InvitationLayoutVariant::STANDARD;
        $variantPartial = 'events.invitations.layouts.'.$layoutVariant.'.sections.'.$section['type'];
        $defaultPartial = 'events.invitations.sections.'.$section['type'];
    @endphp

    @if (in_array($section['type'], \App\Support\InvitationLayoutVariant::blockedSections($layoutVariant), true))
        @continue
    @endif

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
            @includeFirst([$variantPartial, $defaultPartial])
            @break

        @case('countdown')
            @includeFirst([$variantPartial, $defaultPartial])
            @break

        @case('gallery')
            @includeFirst([$variantPartial, $defaultPartial])
            @break
    @endswitch
@endforeach
