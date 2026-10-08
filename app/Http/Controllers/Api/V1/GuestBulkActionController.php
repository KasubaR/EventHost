<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GuestBulkActionApiRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CommunicationService;
use App\Support\GuestPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * JSON sibling of App\Http\Controllers\GuestBulkActionController. Authorizes via
 * EventPolicy directly instead of the narrower owner-only FormRequest check (Slice
 * C3 plan, design decision #1). Drops the filter-preserving redirect entirely — the
 * Android client already knows its own filter state; returns {action,
 * affected_count} instead of redirect+flash. Web controller untouched.
 */
class GuestBulkActionController extends Controller
{
    public function store(
        GuestBulkActionApiRequest $request,
        Event $event,
        CommunicationService $communicationService
    ): JsonResponse {
        $this->authorize('update', $event);
        abort_unless($event->isInvitation(), 404);

        $validated = $request->validated();
        $ids = $validated['guest_ids'];
        $action = $validated['action'];
        $bulkCount = 0;

        if ($action === 'send_reminder_email' && ! $event->ownerCanSendAutomatedReminders()) {
            return response()->json([
                'error' => 'plan_required',
                'required_tier' => 'pro_plus',
                'message' => 'Reminder emails require the Pro+ plan. Upgrade to send them.',
            ], 422);
        }

        // Reminders and WhatsApp shares point guests at the RSVP form; while it is closed they would land on a dead
        // end (plans/rsvp-deadline-fixes.md G10).
        if (in_array($action, ['send_reminder_email', 'prepare_whatsapp_share'], true) && ($reason = $event->rsvpClosedReason()) !== null) {
            return response()->json([
                'error' => 'rsvp_closed',
                'message' => $reason.' Extend the RSVP deadline first, then try again.',
            ], 422);
        }

        // Guests the action could not reach: no email for the two email actions, no usable phone for the WhatsApp share.
        $skippedCount = 0;

        DB::transaction(function () use ($event, $ids, $action, $validated, $communicationService, &$bulkCount, &$skippedCount): void {
            $builder = Guest::query()
                ->where('event_id', $event->id)
                ->whereIn('id', $ids)
                ->lockForUpdate();

            if ($action === 'assign_group') {
                $bulkCount = $builder->update([
                    'guest_group_id' => isset($validated['guest_group_id']) && $validated['guest_group_id'] !== null
                        ? (int) $validated['guest_group_id']
                        : null,
                ]);

                return;
            }

            if ($action === 'assign_table') {
                $bulkCount = $builder->update([
                    'event_table_id' => isset($validated['event_table_id']) && $validated['event_table_id'] !== null
                        ? (int) $validated['event_table_id']
                        : null,
                ]);

                return;
            }

            if ($action === 'mark_sent') {
                $bulkCount = $builder->update([
                    'invitation_sent' => true,
                    'invitation_sent_at' => now(),
                ]);

                return;
            }

            // Does not touch anyone's confirmed seats: it only lets these guests pick a plus-one from now on.
            if ($action === 'allow_plus_one') {
                $bulkCount = $builder->update(['plus_one_allowed' => true]);

                return;
            }

            if ($action === 'delete') {
                $bulkCount = $builder->delete();

                return;
            }

            $guests = $builder->get();
            foreach ($guests as $guest) {
                if ($action === 'prepare_whatsapp_share') {
                    // Only a guest the share link can actually reach is marked sent; anyone else would read "Sent" forever.
                    if ($guest->invitation_token === null || GuestPhone::whatsAppDigits($guest->phone) === null) {
                        $skippedCount++;

                        continue;
                    }
                    $guest->forceFill([
                        'invitation_sent' => true,
                        'invitation_sent_at' => $guest->invitation_sent_at ?? now(),
                    ])->save();
                    $bulkCount++;

                    continue;
                }

                if ($action === 'send_reminder_email') {
                    if ($guest->rsvp()->exists()) {
                        continue;
                    }
                    // The guest asked not to get these; skipped before the bucket is marked sent or counted.
                    if ($guest->hasStoppedEmailReminders()) {
                        continue;
                    }
                    // Nothing can be sent, so the bucket stays unused for when an email is added.
                    if (blank($guest->email)) {
                        $skippedCount++;

                        continue;
                    }
                    $daysUntil = (int) ($validated['days_until'] ?? 3);
                    $bucket = (string) $daysUntil;
                    /** @var list<string> $sentBuckets */
                    $sentBuckets = $guest->rsvp_reminders_sent;
                    if (in_array($bucket, $sentBuckets, true)) {
                        continue;
                    }

                    $idempotencyKey = sprintf('bulk-reminder:%d:%d:%d:%s', $event->id, $guest->id, $daysUntil, $event->rsvpDeadlineKeyStamp());
                    $communicationService->sendRsvpReminder($event, $guest, $daysUntil, $idempotencyKey);

                    $sentBuckets[] = $bucket;
                    $guest->forceFill(['rsvp_reminders_sent' => array_values(array_unique($sentBuckets))])->saveQuietly();
                    $bulkCount++;

                    continue;
                }

                if ($action === 'send_update_email') {
                    $updateMessage = (string) ($validated['update_message'] ?? '');
                    if ($updateMessage === '') {
                        continue;
                    }
                    if (blank($guest->email)) {
                        $skippedCount++;

                        continue;
                    }
                    $communicationService->sendEventUpdate($event, $guest, $updateMessage);
                    $bulkCount++;
                }
            }
        });

        return response()->json([
            'action' => $action,
            'affected_count' => $bulkCount,
            'skipped_count' => $skippedCount,
        ]);
    }
}
