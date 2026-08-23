<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptEventStaffInvitationRequest;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Public accept flow for an EventStaffInviteNotification link. Twin paths:
 * the invited email either already has a User (confirm(), auth-protected —
 * Laravel's normal "log in, then come back" intended-URL redirect does the
 * work) or it doesn't (show()/store() — set a password, account created on
 * the spot). See plans/staff-access.md.
 */
class EventStaffInvitationController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $eventStaff = $this->findAcceptable($token);

        if (User::query()->where('email', $eventStaff->email)->exists()) {
            return redirect()->route('staff-invitations.confirm', $token);
        }

        return view('staff-invitations.show', compact('eventStaff'));
    }

    public function store(AcceptEventStaffInvitationRequest $request, string $token): RedirectResponse
    {
        $eventStaff = $this->findAcceptable($token);

        abort_if(User::query()->where('email', $eventStaff->email)->exists(), 404);

        $validated = $request->validated();

        // The signed invite link is itself proof of mailbox ownership — the
        // same trust basis VerifyEmailController relies on — so no separate
        // verification email is needed here. email_verified_at and status
        // aren't fillable (by design, set via events/notify flows, not mass
        // assignment), so both are forced in a second step, together — same
        // pair VerifyEmailController sets atomically. Skipping `status` here
        // would leave the account verified but permanently 'pending', an
        // invariant (verified <=> active) nothing else in the app breaks.
        $user = User::create([
            'name' => $validated['name'],
            'email' => $eventStaff->email,
            'password' => $validated['password'],
        ]);
        $user->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();

        event(new Registered($user));
        event(new Verified($user));

        $eventStaff->forceFill([
            'user_id' => $user->id,
            'accepted_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ])->save();

        Auth::login($user);

        return redirect()
            ->route('events.show', $eventStaff->event)
            ->with('status', 'staff-invite-accepted');
    }

    /**
     * Auth + verified protected on purpose (see routes/web.php) — an
     * unauthenticated visit gets Laravel's normal login redirect and returns
     * here automatically once they sign in.
     */
    public function confirm(Request $request, string $token): RedirectResponse
    {
        $eventStaff = $this->findAcceptable($token);

        $user = $request->user();

        abort_unless($user !== null && strcasecmp($user->email, $eventStaff->email) === 0, 403,
            "This invite is for {$eventStaff->email}. Log out and sign in with that address to accept it.");

        $eventStaff->forceFill([
            'user_id' => $user->id,
            'accepted_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ])->save();

        return redirect()
            ->route('events.show', $eventStaff->event)
            ->with('status', 'staff-invite-accepted');
    }

    private function findAcceptable(string $token): EventStaff
    {
        $eventStaff = EventStaff::query()
            ->where('invite_token', $token)
            ->whereNull('accepted_at')
            ->firstOrFail();

        abort_if($eventStaff->isExpired(), 410, 'This invite link has expired. Ask the event owner to resend it.');

        return $eventStaff;
    }
}
