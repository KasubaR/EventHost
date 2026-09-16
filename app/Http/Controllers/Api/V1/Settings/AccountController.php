<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Enums\TicketOrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * JSON sibling of App\Http\Controllers\Settings\AccountController (Slice E).
 * Same paid-ticket-sales block as the web controller, verbatim. Password
 * re-check uses the same Auth::guard('web')->validate() trick as
 * SecurityController (the implicit `current_password` rule assumes the
 * `web` guard). Revokes only the token used for this request — a delete
 * from one device shouldn't need every other logged-in device to have
 * already logged out for the check to make sense, and there is no session
 * to invalidate here as the web controller does.
 */
class AccountController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $validated['password']])) {
            throw ValidationException::withMessages([
                'password' => trans('auth.password'),
            ]);
        }

        // events.user_id cascades on delete, and so do ticket_orders/tickets/
        // ticket_reservations off the events it takes with it — deleting the
        // account would silently destroy paid buyers' tickets and orders.
        $hasPaidTicketSales = $user->events()
            ->ticketed()
            ->whereHas('ticketOrders', fn ($query) => $query->where('status', TicketOrderStatus::Paid->value))
            ->exists();

        if ($hasPaidTicketSales) {
            return response()->json([
                'message' => 'You have ticketed events with paid orders. Contact support to wind down '
                    .'ticket sales — and settle any pending payout — before deleting your account.',
            ], 409);
        }

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        $request->user()->currentAccessToken()->delete();

        $user->delete();

        return response()->json(['message' => 'Account deleted.']);
    }
}
