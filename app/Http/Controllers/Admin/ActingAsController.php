<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpRequestKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActingAsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActingAsController extends Controller
{
    public function store(Request $request, User $user, ActingAsService $acting): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        if ($reason = $acting->refusal($admin, $user)) {
            return back()->with('error', $reason);
        }

        $helpRequest = $acting->grantingRequest($admin, $user);

        $acting->start(
            $request,
            $admin,
            $user,
            $helpRequest ? route('admin.help-requests.show', $helpRequest) : route('admin.users.show', $user),
            $helpRequest,
        );

        // Land on what the client asked for: their event, the new-event wizard, or the list.
        if ($helpRequest?->event_id) {
            return redirect()->route('events.edit', $helpRequest->event_id);
        }

        return redirect()->route($helpRequest?->kind === HelpRequestKind::CreateEvent ? 'events.create' : 'events.index');
    }

    public function destroy(Request $request, ActingAsService $acting): RedirectResponse
    {
        $returnUrl = $acting->isActive($request) ? $acting->stop($request) : null;

        return redirect($returnUrl ?? route('admin.dashboard'))->with('status', 'You have stopped acting as the client.');
    }
}
