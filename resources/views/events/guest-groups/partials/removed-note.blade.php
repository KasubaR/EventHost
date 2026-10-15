{{-- plans/guest-groups-gaps.md Phase 1: what a group delete / "turn off link" changed, from GuestGroupDeleter's counts. --}}
@php $gr = session('group_removed'); @endphp
@if ($gr)
    @if ($gr['kind'] === 'deleted' && $gr['members'] > 0)
        <div class="evt-admin-flash"><bdi>{{ $gr['name'] }}</bdi> had {{ $gr['members'] }} {{ Str::plural('guest', $gr['members']) }}; they are still on the list, without a group.</div>
    @endif
    @if ($gr['joiners'] > 0)
        <div class="evt-admin-flash">
            {{ $gr['joiners'] }} {{ Str::plural('guest', $gr['joiners']) }} signed up through that link and {{ $gr['joiners'] === 1 ? 'stays' : 'stay' }} under your review.
            @if ($gr['pending'] > 0)
                {{ $gr['pending'] }} {{ $gr['pending'] === 1 ? 'is' : 'are' }} waiting for you on the guest list.
            @endif
        </div>
    @endif
@endif
