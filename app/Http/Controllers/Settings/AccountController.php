<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.account', [
            'user' => $request->user(),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // events.user_id cascades, and so do the orders and contribution payments under an
        // event — deleting the account would silently destroy money records we keep. The
        // service refuses when any event, trashed ones included, has taken money (the same
        // definition the purge uses) or a payment of the user's is still settling, and keeps
        // the user's own payment history. See plans/event-retention.md §6 and §6b.
        $blocker = app(AccountDeletionService::class)->delete($user);

        if ($blocker !== null) {
            return redirect()->back()->withErrors([
                'blocked' => AccountDeletionService::messageFor($blocker),
            ], 'userDeletion');
        }

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/');
    }
}
