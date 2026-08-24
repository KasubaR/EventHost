<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TicketOrderStatus;
use App\Http\Controllers\Controller;
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

        // events.user_id cascades on delete, and so do ticket_orders/tickets/
        // ticket_reservations off the events it takes with it — deleting the
        // account would silently destroy paid buyers' tickets and orders.
        // Block it here rather than loosen those FKs: the ticket dashboard,
        // check-in and resend flows all assume a ticket's event still exists.
        $hasPaidTicketSales = $user->events()
            ->ticketed()
            ->whereHas('ticketOrders', fn ($query) => $query->where('status', TicketOrderStatus::Paid->value))
            ->exists();

        if ($hasPaidTicketSales) {
            return redirect()->back()->withErrors([
                'blocked' => 'You have ticketed events with paid orders. Contact support to wind down '
                    .'ticket sales — and settle any pending payout — before deleting your account.',
            ], 'userDeletion');
        }

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/');
    }
}
