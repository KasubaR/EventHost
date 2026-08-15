<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestBulkActionRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CommunicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class GuestBulkActionController extends Controller
{
    public function store(
        GuestBulkActionRequest $request,
        Event $event,
        CommunicationService $communicationService
    ): RedirectResponse {
        $validated = $request->validated();
        $ids = $validated['guest_ids'];
        $action = $validated['action'];
        $bulkCount = 0;

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

        $filters = array_filter([
            'q' => $request->input('q'),
            'response' => $request->input('response'),
            'group' => $request->input('group'),
            'invitation_sent' => $request->input('invitation_sent'),
            'plus_one' => $request->input('plus_one'),
            'checked_in' => $request->input('checked_in'),
        ], fn ($v) => $v !== null && $v !== '');

        return redirect()
            ->route('events.guests.index', array_merge(['event' => $event], $filters))
            ->with('bulk_count', $bulkCount)
            ->with('status', match ($action) {
                'assign_group' => 'guests-bulk-group',
                'assign_table' => 'guests-bulk-table',
                'mark_sent' => 'guests-bulk-sent',
                'delete' => 'guests-bulk-deleted',
                'send_reminder_email' => 'guests-bulk-reminder',
                'send_update_email' => 'guests-bulk-update',
                'prepare_whatsapp_share' => 'guests-bulk-whatsapp',
                default => 'guests-bulk-done',
            });
    }
}
