{{-- Shown after a save that switched plus-ones on. The event-wide toggle does nothing for guests whose own
     flag is off, so this offers to turn it on for all of them. plans/plus-one-edge-cases.md Phase 2. --}}
@if (session('plus_ones_available'))
    @php $available = session('plus_ones_available'); @endphp
    <div class="evt-flash evt-flash--info" role="status">
        <i class="fa-solid fa-user-plus"></i>
        Plus-ones are on, but {{ $available['count'] }} {{ \Illuminate\Support\Str::plural('guest', $available['count']) }}
        can't pick one yet.
        <form method="post" action="{{ $available['url'] }}" style="display:inline">
            @csrf
            <button type="submit" class="evt-btn-outline evt-btn-tiny">Allow for all guests</button>
        </form>
    </div>
@endif
