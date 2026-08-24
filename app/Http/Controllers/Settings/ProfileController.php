<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.profile', [
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateProfileRequest $request, ProfileService $profileService): RedirectResponse
    {
        $profileService->update($request->user(), $request);

        return redirect()
            ->route('settings.profile.edit')
            ->with('status', 'profile-updated');
    }

    public function destroyPhoto(Request $request, ProfileService $profileService): RedirectResponse
    {
        $profileService->removePhoto($request->user());

        return redirect()
            ->route('settings.profile.edit')
            ->with('status', 'photo-removed');
    }
}
