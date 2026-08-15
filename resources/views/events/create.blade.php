<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/events-form.js') }}" defer></script>
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
    @endpush

    <x-slot name="title">Create event</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Create event</h1>
                <p class="dph-sub">Add details and save as a draft — drafts are free. Publishing uses 1 event credit.</p>
            </div>
            <a href="{{ route('events.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to events</a>
        </div>
    </x-slot>

    @include('events.partials.steps', ['current' => 1])

    <form method="post" action="{{ route('events.store') }}" enctype="multipart/form-data" class="profile-form">
        @csrf
        @if (! empty($prefTemplateId))
            <input type="hidden" name="preferred_invitation_template_id" value="{{ $prefTemplateId }}">
        @endif
        @include('events.partials.form-fields', ['event' => null])

        <div class="evt-section-body evt-actions-bar">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save draft
            </button>
            <span class="evt-muted">Publishing is available after save and uses 1 event credit.</span>
        </div>
    </form>
</x-app-layout>
