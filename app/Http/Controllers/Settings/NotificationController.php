<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.notifications', [
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request, ProfileService $profileService): RedirectResponse
    {
        $profileService->updateNotificationPreferences($request->user(), $request->preferences());

        return redirect()
            ->route('settings.notifications.edit')
            ->with('status', 'preferences-updated');
    }
}
