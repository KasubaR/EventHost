<?php

namespace App\Http\Controllers\Admin;

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

        $acting->start($request, $admin, $user, route('admin.users.show', $user));

        return redirect()->route('events.index');
    }

    public function destroy(Request $request, ActingAsService $acting): RedirectResponse
    {
        $returnUrl = $acting->isActive($request) ? $acting->stop($request) : null;

        return redirect($returnUrl ?? route('admin.dashboard'))->with('status', 'You have stopped acting as the client.');
    }
}
