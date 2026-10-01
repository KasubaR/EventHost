<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * An "acting as client" session: the `web` guard is logged in as the client while
 * the admin's identity stays on the `admin` guard in the same session, so the
 * existing host screens run unchanged with the client's credits, tier gates and
 * ownership. Plan: plans/admin-create-events.md (Step 1).
 *
 * The web guard is signed in and out through the guard itself, never through the
 * login or logout controllers: those write last_login_*, rotate the client's
 * remember token and invalidate the whole session (which would sign the admin out).
 */
class ActingAsService
{
    public const KEY = 'acting_as';

    public function enabled(): bool
    {
        return (bool) config('admin.acting_as.enabled');
    }

    /**
     * Why this admin may not act as this client, or null when they may.
     */
    public function refusal(Admin $admin, User $client): ?string
    {
        if (! $this->enabled()) {
            return 'Acting as a client is switched off.';
        }

        if (! $admin->can('users.act_as')) {
            return 'You do not have permission to act as a client.';
        }

        if ($client->status !== 'active') {
            return 'Only an active account can be acted on. This one is '.$client->status.'.';
        }

        if ($client->email_verified_at === null) {
            return 'This client has not verified their email, so the host screens would not open.';
        }

        if ($this->isStaffIdentity($client)) {
            return 'This account belongs to a member of staff and cannot be acted on.';
        }

        if (Auth::guard('web')->check()) {
            return 'Sign out of the account this browser is already using first.';
        }

        if (! $this->hasConsent($admin, $client)) {
            return 'This client has not asked for our help, so there is nothing to act on.';
        }

        return null;
    }

    public function start(Request $request, Admin $admin, User $client, string $returnUrl): void
    {
        Auth::guard('web')->login($client);

        $request->session()->put(self::KEY, [
            'admin_id' => $admin->id,
            'user_id' => $client->id,
            'started_at' => now()->getTimestamp(),
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * End the session and return the URL to send the admin back to.
     */
    public function stop(Request $request): ?string
    {
        $returnUrl = $request->session()->get(self::KEY.'.return_url');

        $guard = Auth::guard('web');
        $guard->forgetUser();
        $request->session()->forget([$guard->getName(), 'password_hash_web', self::KEY]);
        $request->session()->regenerate();

        return is_string($returnUrl) ? $returnUrl : null;
    }

    public function isActive(?Request $request = null): bool
    {
        $request ??= request();

        return $request->hasSession() && $request->session()->has(self::KEY.'.admin_id');
    }

    public function admin(?Request $request = null): ?Admin
    {
        $request ??= request();
        $id = $this->isActive($request) ? $request->session()->get(self::KEY.'.admin_id') : null;

        return $id === null ? null : Admin::query()->find($id);
    }

    /**
     * Why a live session must end now, or null when it may carry on.
     */
    public function invalidReason(Request $request): ?string
    {
        $session = $request->session()->get(self::KEY, []);
        $adminGuard = Auth::guard('admin');
        $client = Auth::guard('web')->user();

        if (! $this->enabled()) {
            return 'Acting as a client has been switched off.';
        }

        if (! $adminGuard->check() || (int) $adminGuard->id() !== (int) ($session['admin_id'] ?? 0)) {
            return 'The admin session behind this one has ended.';
        }

        if ($client === null || (int) $client->id !== (int) ($session['user_id'] ?? 0)) {
            return 'The client session has ended.';
        }

        if ($client->status !== 'active') {
            return 'This client account is no longer active.';
        }

        $ageSeconds = now()->getTimestamp() - (int) ($session['started_at'] ?? 0);

        if ($ageSeconds >= max(1, (int) config('admin.acting_as.ttl_minutes')) * 60) {
            return 'The session timed out.';
        }

        return null;
    }

    /**
     * The consent gate. Plan Step 0 replaces the body with a lookup of an in-progress
     * help request assigned to this admin (scoped to its event when it has one).
     */
    private function hasConsent(Admin $admin, User $client): bool
    {
        return ! config('admin.acting_as.require_help_request');
    }

    private function isStaffIdentity(User $client): bool
    {
        return Admin::query()
            ->where('user_id', $client->id)
            ->orWhere('email', $client->email)
            ->exists();
    }
}
