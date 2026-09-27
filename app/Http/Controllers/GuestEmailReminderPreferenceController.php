<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use Illuminate\View\View;

/**
 * The "stop these reminder emails" link at the foot of every guest reminder email
 * (plans/guest-email-reminders.md Phase 3). A guest has no account, so the link is the only credential:
 * a *relative* signed URL keyed on the guest id — it does not expose the private RSVP token, works for a
 * guest who has none, and stays valid whichever host the request arrives on (the bare domain redirects
 * to www).
 *
 * GET only shows a page; the change is a POST. Mail scanners and link previewers fetch GET links, and a
 * scanner that opted every guest out just by opening the email would be worse than no link. The same
 * POST is what a mail client's one-click "unsubscribe" button sends (RFC 8058), so it is exempt from CSRF —
 * the signature is what authorises it.
 */
class GuestEmailReminderPreferenceController extends Controller
{
    public function show(Guest $guest): View
    {
        return $this->page($guest, $guest->hasStoppedEmailReminders() ? 'stopped' : 'confirm');
    }

    public function stop(Guest $guest): View
    {
        if (! $guest->hasStoppedEmailReminders()) {
            $guest->forceFill(['email_reminders_stopped_at' => now()])->save();
        }

        return $this->page($guest, 'stopped');
    }

    public function resume(Guest $guest): View
    {
        $guest->forceFill(['email_reminders_stopped_at' => null])->save();

        return $this->page($guest, 'resumed');
    }

    /**
     * @param  'confirm'|'stopped'|'resumed'  $state
     */
    private function page(Guest $guest, string $state): View
    {
        return view('rsvp.email-reminders', [
            'state' => $state,
            // Null when the event was deleted; the page then just says "this event".
            'event' => $guest->event,
            'stopUrl' => $guest->stopEmailRemindersPath(),
            'resumeUrl' => $guest->resumeEmailRemindersPath(),
        ]);
    }
}
