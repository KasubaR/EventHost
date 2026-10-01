<?php

namespace App\Services;

use App\Enums\HelpRequestStatus;
use App\Models\Admin;
use App\Models\AdminHelpRequest;
use App\Models\User;
use App\Notifications\HelpRequestClaimedNotification;
use App\Notifications\HelpRequestDeclinedNotification;
use Illuminate\Support\Facades\DB;

/**
 * Every state change of a help request goes through here, each under a row lock so two
 * admins claiming at once, or a client cancelling mid-claim, cannot both win. Plan:
 * plans/admin-create-events.md (Step 0).
 */
class HelpRequestService
{
    /**
     * One open request per client at a time: returns null when they already have one.
     *
     * @param  array{kind: string, message: string, event_id?: int|null, contact_preference?: string|null}  $data
     */
    public function create(User $user, array $data): ?AdminHelpRequest
    {
        return DB::transaction(function () use ($user, $data): ?AdminHelpRequest {
            // Serialises two submissions from the same client; the table has no unique index
            // that could express "one current request" because current depends on the clock.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            AdminHelpRequest::expireStale();

            if (AdminHelpRequest::query()->where('user_id', $user->id)->current()->exists()) {
                return null;
            }

            return AdminHelpRequest::query()->create([
                'user_id' => $user->id,
                'event_id' => $data['event_id'] ?? null,
                'kind' => $data['kind'],
                'message' => $data['message'],
                'contact_preference' => $data['contact_preference'] ?? null,
                'status' => HelpRequestStatus::Open,
            ]);
        });
    }

    /**
     * The client withdrawing. Ends any live acting-as session at its next request,
     * because ActingAsService re-checks the request every time.
     */
    public function cancel(AdminHelpRequest $helpRequest): bool
    {
        return $this->transition($helpRequest, [HelpRequestStatus::Open, HelpRequestStatus::InProgress], [
            'status' => HelpRequestStatus::Cancelled,
            'access_expires_at' => now(),
        ]);
    }

    /**
     * Assigns the request to this admin and opens the access window.
     */
    public function claim(AdminHelpRequest $helpRequest, Admin $admin): bool
    {
        $claimed = $this->transition($helpRequest, [HelpRequestStatus::Open], [
            'status' => HelpRequestStatus::InProgress,
            'assigned_admin_id' => $admin->id,
            'claimed_at' => now(),
            'access_expires_at' => now()->addDays(max(1, (int) config('admin.help_requests.access_days'))),
        ]);

        if ($claimed) {
            $helpRequest->user->notify(new HelpRequestClaimedNotification($helpRequest->load('assignedAdmin')));
        }

        return $claimed;
    }

    public function complete(AdminHelpRequest $helpRequest): bool
    {
        return $this->transition($helpRequest, [HelpRequestStatus::InProgress], [
            'status' => HelpRequestStatus::Completed,
            'completed_at' => now(),
            'access_expires_at' => now(),
        ]);
    }

    public function decline(AdminHelpRequest $helpRequest, ?string $note): bool
    {
        $declined = $this->transition($helpRequest, [HelpRequestStatus::Open, HelpRequestStatus::InProgress], [
            'status' => HelpRequestStatus::Declined,
            'decline_note' => $note,
            'access_expires_at' => now(),
        ]);

        if ($declined) {
            $helpRequest->user->notify(new HelpRequestDeclinedNotification($helpRequest));
        }

        return $declined;
    }

    /**
     * @param  list<HelpRequestStatus>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(AdminHelpRequest $helpRequest, array $from, array $changes): bool
    {
        return DB::transaction(function () use ($helpRequest, $from, $changes): bool {
            $fresh = AdminHelpRequest::query()->whereKey($helpRequest->id)->lockForUpdate()->first();

            if ($fresh === null || ! in_array($fresh->status, $from, true)) {
                return false;
            }

            $fresh->update($changes);
            $helpRequest->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }
}
