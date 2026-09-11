<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GuestBulkActionApiRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CommunicationService;
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

        DB::transaction(function () use ($event, $ids, $action, $validated, $communicationService, &$bulkCount): void {
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

            if ($action === 'delete') {
                $bulkCount = $builder->delete();

                return;
            }

            $guests = $builder->get();
            foreach ($guests as $guest) {
                if ($action === 'prepare_whatsapp_share') {
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
                    $daysUntil = (int) ($validated['days_until'] ?? 3);
                    $bucket = (string) $daysUntil;
                    /** @var list<string> $sentBuckets */
                    $sentBuckets = $guest->rsvp_reminders_sent;
                    if (in_array($bucket, $sentBuckets, true)) {
                        continue;
                    }

                    $idempotencyKey = sprintf('bulk-reminder:%d:%d:%d', $event->id, $guest->id, $daysUntil);
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
                    $communicationService->sendEventUpdate($event, $guest, $updateMessage);
                    $bulkCount++;
                }
            }
        });

        return response()->json([
            'action' => $action,
            'affected_count' => $bulkCount,
        ]);
    }
}
