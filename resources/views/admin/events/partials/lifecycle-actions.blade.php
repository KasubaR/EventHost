@if ($ev->isCancelled())
    <form method="post" action="{{ route('admin.events.uncancel', $ev) }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="btn-primary"><i class="fa-solid fa-rotate-left"></i> Reopen event</button>
    </form>
@else
    @if ($ev->isInvitationPaused())
        <form method="post" action="{{ route('admin.events.resume', $ev) }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn-primary"><i class="fa-solid fa-play"></i> Resume invitation</button>
        </form>
    @else
        <form method="post" action="{{ route('admin.events.pause', $ev) }}" data-confirm="Pause this invitation? Guests will see “Invitation unavailable”.">
            @csrf
            @method('PATCH')
            <button type="submit" class="evt-btn-outline"><i class="fa-solid fa-pause"></i> Pause invitation</button>
        </form>
    @endif
    <form method="post" action="{{ route('admin.events.cancel', $ev) }}" data-confirm="Cancel this event? Guests will see “Event cancelled”.">
        @csrf
        @method('PATCH')
        <button type="submit" class="evt-btn-outline evt-btn-danger-outline"><i class="fa-solid fa-ban"></i> Cancel event</button>
    </form>
@endif
