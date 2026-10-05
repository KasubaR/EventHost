@props(['event', 'merged' => null, 'onEditPage' => false])

{{-- Host-side notices about the event's invitation layout (no layout on a live event, a retired layout,
     a plan that no longer covers it, a layout made for another event type) and its saved design
     (details hidden, design restored from the previous copy). Which apply is decided by
     App\Support\InvitationTemplateNotices and App\Support\InvitationDesignNotices. --}}
@foreach ([
    ...\App\Support\InvitationTemplateNotices::for($event),
    ...\App\Support\InvitationDesignNotices::for($event, $merged, $onEditPage),
] as $notice)
    <div class="evt-flash evt-flash--{{ $notice['tone'] }}" role="status">
        <i class="fa-solid {{ $notice['icon'] }}"></i>
        {{ $notice['message'] }}
        @if ($notice['link'])
            <a href="{{ $notice['link'] }}">{{ $notice['link_label'] }}</a>.
        @endif
    </div>
@endforeach
