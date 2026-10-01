<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AdminHelpRequest;
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

        if (config('admin.acting_as.require_help_request') && $this->grantingRequest($admin, $client) === null) {
            return 'This client has not asked for our help, so there is nothing to act on.';
        }

        return null;
    }

    public function start(Request $request, Admin $admin, User $client, string $returnUrl, ?AdminHelpRequest $helpRequest = null): void
    {
        Auth::guard('web')->login($client);

        $request->session()->put(self::KEY, [
            'admin_id' => $admin->id,
            'user_id' => $client->id,
            'started_at' => now()->getTimestamp(),
            'return_url' => $returnUrl,
            'help_request_id' => $helpRequest?->id,
            // Set when the request is about one event: the session may then touch only that event.
            'event_id' => $helpRequest?->event_id,
        ]);

        $this->log($request, 'session_started', $helpRequest?->event_id);
    }

    /**
     * End the session and return the URL to send the admin back to.
     */
    public function stop(Request $request, string $reason = 'exited'): ?string
    {
        $this->log($request, 'session_ended', null, ['reason' => $reason]);

        $returnUrl = $request->session()->get(self::KEY.'.return_url');

        $guard = Auth::guard('web');
        $guard->forgetUser();
        $request->session()->forget([$guard->getName(), 'password_hash_web', self::KEY]);
        $request->session()->regenerate();

        return is_string($returnUrl) ? $returnUrl : null;
    }

    /**
     * Append one row to the audit trail for the live session. Never throws: a logging fault
     * must not break the client's save, but it is reported so it cannot go unnoticed.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(Request $request, string $action, ?int $eventId = null, array $properties = []): void
    {
        if (! $this->isActive($request)) {
            return;
        }

        $session = $request->session()->get(self::KEY, []);

        try {
            AdminActivityLog::query()->create([
                'admin_id' => $session['admin_id'] ?? null,
                'user_id' => $session['user_id'] ?? null,
                'event_id' => $eventId,
                'help_request_id' => $session['help_request_id'] ?? null,
                'action' => $action,
                'properties' => $properties === [] ? null : $properties,
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function isActive(?Request $request = null): bool
    {
        $request ??= request();

        return $request->hasSession() && $request->session()->has(self::KEY.'.admin_id');
    }

    /**
     * Whole minutes left before the session ceiling ends it, never below 1 while it is live.
     */
    public function minutesRemaining(?Request $request = null): int
    {
        $request ??= request();
        $startedAt = (int) $request->session()->get(self::KEY.'.started_at', 0);
        $left = $startedAt + max(1, (int) config('admin.acting_as.ttl_minutes')) * 60 - now()->getTimestamp();

        return max(1, (int) ceil($left / 60));
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

        if (config('admin.acting_as.require_help_request')) {
            $helpRequest = AdminHelpRequest::query()->find($session['help_request_id'] ?? 0);

            if ($helpRequest === null || ! $helpRequest->grantsAccess() || $helpRequest->assigned_admin_id !== $session['admin_id']) {
                return 'The client\'s help request is no longer open.';
            }
        }

        $ageSeconds = now()->getTimestamp() - (int) ($session['started_at'] ?? 0);

        if ($ageSeconds >= max(1, (int) config('admin.acting_as.ttl_minutes')) * 60) {
            return 'The session timed out.';
        }

        return null;
    }

    /**
     * The client's in-progress help request assigned to this admin — the consent that
     * makes acting as them legitimate. Null when there is none.
     */
    public function grantingRequest(Admin $admin, User $client): ?AdminHelpRequest
    {
        return AdminHelpRequest::query()
            ->where('user_id', $client->id)
            ->where('assigned_admin_id', $admin->id)
            ->grantingAccess()
            ->latest('claimed_at')
            ->first();
    }

    /**
     * The one event this session is limited to, or null when it may use the whole account.
     */
    public function scopedEventId(?Request $request = null): ?int
    {
        $request ??= request();
        $id = $this->isActive($request) ? $request->session()->get(self::KEY.'.event_id') : null;

        return $id === null ? null : (int) $id;
    }

    private function isStaffIdentity(User $client): bool
    {
        return Admin::query()
            ->where('user_id', $client->id)
            ->orWhere('email', $client->email)
            ->exists();
    }
}
