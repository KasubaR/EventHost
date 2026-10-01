<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Help request #{{ $helpRequest->id }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.help-requests.index') }}">Help requests</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>#{{ $helpRequest->id }}</span>
                </nav>
                <h1 class="dph-title">Help request #{{ $helpRequest->id }}</h1>
                <p class="dph-sub">{{ $helpRequest->status->label() }}</p>
            </div>
            <a href="{{ route('admin.help-requests.index') }}" class="evt-btn-outline dash-header-cta">Back</a>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="evt-flash evt-flash--warn" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> {{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="evt-flash evt-flash--warn" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>The request</h2>
            <p class="admin-muted admin-mt-sm"><strong>Client:</strong>
                <a href="{{ route('admin.users.show', $helpRequest->user) }}" class="admin-link">{{ $helpRequest->user->name }}</a>
                ({{ $helpRequest->user->email }})
            </p>
            <p class="admin-muted"><strong>Credits:</strong> {{ $helpRequest->user->event_credits }}
                @if (auth('admin')->user()?->can('users.manage_status'))
                    &middot; <a href="{{ route('admin.users.show', $helpRequest->user) }}#credits-input" class="admin-link">Grant credits</a>
                @endif
            </p>
            <p class="admin-muted"><strong>Request:</strong> {{ $helpRequest->kind->label() }}</p>
            @if ($helpRequest->event)
                <p class="admin-muted"><strong>Event:</strong> {{ $helpRequest->event->name }}</p>
            @endif
            <p class="admin-muted"><strong>Contact:</strong> {{ $helpRequest->contact_preference ?: '-' }}</p>
            <p class="admin-muted"><strong>Sent:</strong> {{ $helpRequest->created_at->format('M j, Y H:i') }}</p>
            <p class="admin-mt-md">{{ $helpRequest->message }}</p>
            @if ($helpRequest->decline_note)
                <p class="admin-muted admin-mt-md"><strong>Decline note:</strong> {{ $helpRequest->decline_note }}</p>
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Handling</h2>
            <p class="admin-muted admin-mt-sm">
                <strong>Assigned to:</strong> {{ $helpRequest->assignedAdmin?->name ?? 'Nobody yet' }}
            </p>
            @if ($helpRequest->access_expires_at && $helpRequest->status === \App\Enums\HelpRequestStatus::InProgress)
                <p class="admin-muted"><strong>Access until:</strong> {{ $helpRequest->access_expires_at->format('M j, Y H:i') }}</p>
            @endif

            <div class="admin-actions admin-mt-md">
                @if ($helpRequest->status === \App\Enums\HelpRequestStatus::Open)
                    <form method="post" action="{{ route('admin.help-requests.claim', $helpRequest) }}">
                        @csrf
                        <button type="submit" class="btn-primary">Claim this request</button>
                    </form>
                @endif

                @if ($canAct)
                    <form method="post" action="{{ route('admin.users.act-as', $helpRequest->user) }}">
                        @csrf
                        <button type="submit" class="btn-primary"><i class="fa-solid fa-user-shield"></i> Start acting as {{ $helpRequest->user->name }}</button>
                    </form>
                    <form method="post" action="{{ route('admin.help-requests.complete', $helpRequest) }}">
                        @csrf
                        <button type="submit" class="evt-btn-outline">Mark completed</button>
                    </form>
                @endif
            </div>

            @if ($helpRequest->status->isCurrent())
                <form method="post" action="{{ route('admin.help-requests.decline', $helpRequest) }}" class="profile-form admin-mt-md">
                    @csrf
                    <label for="decline-note">Decline with a note (the client is emailed)</label>
                    <textarea id="decline-note" name="decline_note" rows="3" maxlength="1000" class="profile-input admin-mt-sm"></textarea>
                    <div class="admin-actions admin-mt-sm">
                        <button type="submit" class="evt-btn-danger-outline">Decline</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-admin-layout>
