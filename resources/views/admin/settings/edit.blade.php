<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Settings</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Platform settings</h1>
                <p class="dph-sub">Key/value preferences stored in the database.</p>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    <div class="admin-panel-card">
        <form method="post" action="{{ route('admin.settings.update') }}" class="profile-form">
            @csrf
            @method('PATCH')

            <label for="site_name">Site display name</label>
            <input id="site_name" type="text" name="site_name" class="profile-input" value="{{ old('site_name', $siteName) }}" required maxlength="120">

            <label for="whatsapp_default_message" class="admin-mt-md">Default WhatsApp share message</label>
            <textarea id="whatsapp_default_message" name="whatsapp_default_message" class="profile-input" rows="4" maxlength="2000">{{ old('whatsapp_default_message', $whatsappDefaultMessage) }}</textarea>
            <p class="admin-muted admin-mt-sm">Used as a template hint for integrations; delivery still follows channel configuration.</p>

            <div class="profile-form-actions admin-mt-md">
                <button type="submit" class="profile-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            </div>
        </form>
    </div>
</x-admin-layout>
