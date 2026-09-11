<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptEventStaffInvitationRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\EventStaffInvitationController (Slice D).
 * The one new App Link this slice adds — `/staff/invitations/{token}` — so a
 * Check-in/Manager invite can be accepted from the app instead of a desktop
 * browser. Same twin-path shape as the web controller: an invited email that
 * already has a User goes through confirm() (auth:sanctum, "log in then
 * confirm" — the app's normal login screen stands in for the web's
 * intended-URL redirect); one that doesn't goes through show()/store(),
 * account created on the spot. Reuses AcceptEventStaffInvitationRequest
 * verbatim. Issues a Sanctum token on accept instead of Auth::login(), same
 * swap RegisteredUserController already makes for the stateless API guard.
 */
class StaffInvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $eventStaff = $this->findAcceptable($token);
        $eventStaff->loadMissing('event');

        return response()->json([
            'event' => [
                'id' => $eventStaff->event->id,
                'name' => $eventStaff->event->name,
                'slug' => $eventStaff->event->slug,
            ],
            'email' => $eventStaff->email,
            'name' => $eventStaff->name,
            'role' => [
                'value' => $eventStaff->role->value,
                'label' => $eventStaff->role->label(),
            ],
            'account_exists' => User::query()->where('email', $eventStaff->email)->exists(),
        ]);
    }

    public function store(AcceptEventStaffInvitationRequest $request, string $token): JsonResponse
    {
        $eventStaff = $this->findAcceptable($token);

        abort_if(User::query()->where('email', $eventStaff->email)->exists(), 404);

        $validated = $request->validated();

        // The signed invite link is itself proof of mailbox ownership, same trust
        // basis as email verification — see the web controller's docblock for why
        // email_verified_at/status are forced together here.
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

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Auth-protected on purpose (see routes/api.php) — an unauthenticated call
     * gets a 401, the app's normal "log in first" flow, same intent as the web
     * route's login-redirect for this same method.
     */
    public function confirm(Request $request, string $token): JsonResponse
    {
        $eventStaff = $this->findAcceptable($token);

        $user = $request->user();

        abort_unless(strcasecmp($user->email, $eventStaff->email) === 0, 403,
            "This invite is for {$eventStaff->email}. Log out and sign in with that address to accept it.");

        $eventStaff->forceFill([
            'user_id' => $user->id,
            'accepted_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ])->save();

        return response()->json(['message' => 'Invite accepted.']);
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

    private function deviceName(Request $request): string
    {
        $name = $request->input('device_name');

        return is_string($name) && $name !== '' ? $name : 'android';
    }
}
