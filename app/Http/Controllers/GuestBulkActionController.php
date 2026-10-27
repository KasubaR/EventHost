<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestBulkActionRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CommunicationService;
use App\Services\GuestDeleter;
use App\Services\GuestUpdater;
use App\Services\WhatsAppShareService;
use App\Support\EmailSkips;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class GuestBulkActionController extends Controller
{
    /**
     * The one-click follow-up to switching plus-ones on (plans/plus-one-edge-cases.md Phase 2): lets every
     * guest on the list pick a plus-one, without posting thousands of ids. Confirmed seats are untouched.
     */
    public function allowPlusOneForAll(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isInvitation(), 404);

        $count = $event->guests()->where('plus_one_allowed', false)->update(['plus_one_allowed' => true]);

        return redirect()
            ->route('events.guests.index', $event)
            ->with('bulk_count', $count)
            ->with('status', 'guests-bulk-plus-one');
    }

    public function store(
        GuestBulkActionRequest $request,
        Event $event,
        CommunicationService $communicationService,
        GuestUpdater $guestUpdater,
        GuestDeleter $guestDeleter,
    ): RedirectResponse {
        abort_unless($event->isInvitation(), 404);

        $validated = $request->validated();
        $ids = $validated['guest_ids'];
        $action = $validated['action'];
        $bulkCount = 0;

        // CommunicationService::sendRsvpReminder() silently no-ops below this
        // event's plan, which is right for the scheduled command (nothing to
        // tell) but wrong here — this is a host clicking a button, and
        // recording rsvp_reminders_sent for a send that never happened would
        // permanently swallow that reminder bucket even after an upgrade.
        if ($action === 'send_reminder_email' && ! $event->ownerCanSendAutomatedReminders()) {
            return back()->withErrors([
                'action' => 'Reminder emails require the Pro+ plan. Upgrade to send them.',
            ]);
        }

        // Both of these point the guest at the RSVP form, which is closed: refuse rather than send people
        // to a dead end (plans/rsvp-deadline-fixes.md G10). Marking sent and update emails are unaffected.
        if (in_array($action, ['send_reminder_email', 'prepare_whatsapp_share'], true) && ($reason = $event->rsvpClosedReason()) !== null) {
            return back()->withErrors(['action' => $reason.' Extend the RSVP deadline first, then try again.']);
        }

        // The reminder's link would show "Invitation unavailable" until then, so the email is dropped when it is sent anyway.
        if ($action === 'send_reminder_email' && ! $event->is_published) {
            return back()->withErrors(['action' => 'Publish the event first. Until then, guest links show "Invitation unavailable".']);
        }

        // Nothing is sent from here: the queue page opens each guest's chat on the host's own phone, one at a time, and a guest
        // is marked sent only when their chat is opened (plans/whatsapp-sharing-gaps.md).
        if ($action === 'prepare_whatsapp_share') {
            if (! $event->is_published) {
                return back()->withErrors(['action' => 'Publish the event first. Until then, guest links show "Invitation unavailable".']);
            }

            $request->session()->put(WhatsAppShareService::QUEUE_SESSION_KEY.'.'.$event->id, [
                'guest_ids' => array_values(array_map('intval', $ids)),
                'started_at' => now()->toIso8601String(),
            ]);

            return redirect()->route('events.guests.whatsapp-queue', $event);
        }

        // Guests the action could not reach. The two email actions count theirs by reason (EmailSkips).
        $skippedCount = 0;
        $skipReason = null;
        $removedSummary = null;
        $emailSkips = new EmailSkips;

        DB::transaction(function () use ($request, $event, $ids, $action, $validated, $communicationService, $guestUpdater, $guestDeleter, $emailSkips, &$removedSummary, &$bulkCount, &$skippedCount, &$skipReason): void {
            $builder = Guest::query()
                ->where('event_id', $event->id)
                ->whereIn('id', $ids)
                ->lockForUpdate();

            // Checked against each group's seat pool in turn; a guest who would overfill it is skipped and reported.
            if ($action === 'assign_group') {
                $result = $guestUpdater->moveManyToGroup(
                    $event,
                    array_map('intval', $ids),
                    isset($validated['guest_group_id']) && $validated['guest_group_id'] !== null ? (int) $validated['guest_group_id'] : null,
                    $request->user()?->id,
                );
                $bulkCount = $result['moved'];
                $skippedCount = $result['skipped'];
                $skipReason = $result['reason'];

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
                $removedSummary = $guestDeleter->deleteMany($event, $ids, $request->user()?->id);
                $bulkCount = $removedSummary['deleted'];

                return;
            }

            $guests = $builder->get();
            foreach ($guests as $guest) {
                if ($action === 'send_reminder_email') {
                    if ($guest->rsvp()->exists()) {
                        continue;
                    }
                    // The guest asked not to get these; skipped before the bucket is marked sent or counted.
                    if ($guest->hasStoppedEmailReminders()) {
                        continue;
                    }
                    // Nothing can be sent, so the bucket stays unused for when an email is added or the address is retried.
                    if ($emailSkips->unreachable($guest)) {
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
                    if ($emailSkips->unreachable($guest) || $emailSkips->sameUpdateRecently($guest, $updateMessage)) {
                        continue;
                    }
                    $communicationService->sendEventUpdate($event, $guest, $updateMessage);
                    $bulkCount++;
                }
            }
        });

        if (in_array($action, ['send_reminder_email', 'send_update_email'], true)) {
            $skippedCount = $emailSkips->total();
            $skipReason = $emailSkips->summary();
        }

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
            ->with('bulk_skipped', $skippedCount)
            ->with('bulk_skip_reason', $skipReason)
            ->with('bulk_removed', $removedSummary)
            ->with('status', match ($action) {
                'assign_group' => 'guests-bulk-group',
                'assign_table' => 'guests-bulk-table',
                'mark_sent' => 'guests-bulk-sent',
                'delete' => 'guests-bulk-deleted',
                'send_reminder_email' => 'guests-bulk-reminder',
                'send_update_email' => 'guests-bulk-update',
                'allow_plus_one' => 'guests-bulk-plus-one',
                default => 'guests-bulk-done',
            });
    }
}
