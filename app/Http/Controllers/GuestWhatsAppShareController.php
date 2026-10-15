<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Guest;
use App\Services\WhatsAppShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The free wa.me share link (plans/whatsapp-sharing-gaps.md Phase 1). open() records that the host opened a guest's chat and
 * hands the browser to WhatsApp; queue() is the page the bulk "Share on WhatsApp" action leads to, one chat per guest.
 * The paid Twilio send lives in GuestController::sendWhatsAppInvitation().
 */
class GuestWhatsAppShareController extends Controller
{
    public function open(Request $request, Event $event, Guest $guest, WhatsAppShareService $share): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);
        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $problem = $share->eventBlockedReason($event) ?? $share->guestProblem($guest);
        if ($problem !== null) {
            return redirect()
                ->route('events.guests.index', $event)
                ->withErrors(['whatsapp_share' => $guest->name.': '.$problem]);
        }

        $url = (string) $share->url($event, $guest);
        $share->recordOpened($event, $guest, $request->user()?->id);

        return redirect()->away($url);
    }

    public function queue(Request $request, Event $event, WhatsAppShareService $share): View|RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isInvitation(), 404);

        $stored = $request->session()->get(WhatsAppShareService::QUEUE_SESSION_KEY.'.'.$event->id);
        if (! is_array($stored) || ! is_array($stored['guest_ids'] ?? null)) {
            return redirect()
                ->route('events.guests.index', $event)
                ->withErrors(['action' => 'Choose guests on the list, then pick "Share on WhatsApp" from the bulk actions.']);
        }

        $ids = array_values(array_map('intval', $stored['guest_ids']));
        $startedAt = Carbon::parse((string) ($stored['started_at'] ?? now()));
        $guests = $event->guests()->whereIn('id', $ids)->orderBy('name')->get();

        $blockedReason = $share->eventBlockedReason($event);
        $reachable = [];
        $unreachable = [];
        foreach ($guests as $guest) {
            $problem = $share->guestProblem($guest);
            if ($problem === null) {
                $reachable[] = $guest;
            } else {
                $unreachable[] = ['guest' => $guest, 'reason' => $problem];
            }
        }

        $openedSinceStart = $share->lastOpened($event, $guests->pluck('id')->all(), $startedAt);
        $openedCount = collect($reachable)->filter(fn (Guest $g) => isset($openedSinceStart[$g->id]))->count();

        return view('events.guests.whatsapp-queue', [
            'event' => $event,
            'reachable' => $reachable,
            'unreachable' => $unreachable,
            'openedSinceStart' => $openedSinceStart,
            'openedCount' => $openedCount,
            'blockedReason' => $blockedReason,
        ]);
    }
}
