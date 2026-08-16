<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOpenRsvpRequest;
use App\Http\Requests\StoreRsvpByTokenRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\CommunicationService;
use App\Services\InvitationCustomizationService;
use App\Services\QrCodeService;
use App\Services\RsvpSubmissionService;
use App\Support\EventCalendarLinks;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RsvpController extends Controller
{
    public function showByToken(string $token, InvitationCustomizationService $customizationService): View
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event', 'event.invitationTemplate', 'rsvp', 'eventTable'])
            ->firstOrFail();

        $event = $guest->event;

        $showEntryPass = $this->guestHasEntryPass($guest, $event);

        if (! $event->isRsvpOpen()) {
            return view('rsvp.closed', [
                'event' => $event,
                'guest' => $guest,
                // A guest who already said yes still needs their pass in the days
                // between the RSVP deadline and the event itself — the most likely
                // time they would actually reach for it. Once the event is over
                // (isLocked()), there is nothing left to show it for.
                'showEntryPass' => $showEntryPass && ! $event->isLocked(),
            ]);
        }

        // Same designed invitation a public visitor sees at events.public — a guest
        // opening their personal link needs to see what they're actually RSVPing to
        // (hero, description, gallery…), not a bare form. merge() already folds in
        // resolveRsvpFormConfig() as $invitation['rsvp_form'], so that no longer
        // needs to be resolved separately here.
        $invitation = $customizationService->merge($event);

        return view('rsvp.token-show', [
            'guest' => $guest,
            'event' => $event,
            'invitation' => $invitation,
            'existingRsvp' => $guest->rsvp,
            'maxAttendees' => $event->maxAttendeeSlotsForGuest($guest),
            'showEntryPass' => $showEntryPass,
        ]);
    }

    /**
     * Renders the same QR a host would download for this guest — the endpoint it
     * points at is unchanged and still requires a staff credential to act on, so
     * showing it to the guest is not a new self-check-in risk (see Guest::checkInQrUrl()).
     *
     * Same trust model as showByToken(): the token in the URL is the only guard,
     * no login. Gated on the same guestHasEntryPass() check the page panel uses,
     * so the <img> this route backs can never 404 for a guest who was just shown it.
     */
    public function entryPassQr(string $token, Request $request, QrCodeService $qrCodeService): Response
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event', 'rsvp'])
            ->first();

        abort_if($guest === null || ! $this->guestHasEntryPass($guest, $guest->event), 404);

        $url = $guest->checkInQrUrl();
        abort_if($url === null, 404);

        // Unlike the host's one-off badge download, the same guest reopens this
        // bookmarked link repeatedly — cache the render rather than regenerating
        // the SVG from scratch on every visit. The token itself is the cache key,
        // so a regenerated invitation_token naturally starts a fresh cache entry.
        $svg = Cache::remember(
            'guest-entry-pass-qr:'.$token,
            now()->addWeek(),
            fn () => $qrCodeService->svg($url)
        );

        $headers = ['Content-Type' => 'image/svg+xml'];

        if ($request->boolean('download')) {
            $filename = Str::slug($guest->name).'-entry-qr.svg';
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        }

        return response($svg, 200, $headers);
    }

    /**
     * Only a guest who RSVP'd attending gets an entry pass, and only while the
     * host's plan actually supports check-in scanning — showing a QR nobody can
     * scan would just confuse the guest. See plans/guest-entry-pass.md §0.
     */
    private function guestHasEntryPass(Guest $guest, Event $event): bool
    {
        $rsvp = $guest->rsvp;

        return $rsvp !== null && $guest->hasEntryPassFor($rsvp, $event);
    }

    public function storeByToken(
        string $token,
        StoreRsvpByTokenRequest $request,
        RsvpSubmissionService $rsvpSubmissionService,
    ): RedirectResponse {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with('event')
            ->firstOrFail();

        $event = $guest->event;

        $payload = $request->validatedRsvpPayload();

        $rsvp = $rsvpSubmissionService->submit($event, $guest, $payload);

        $this->dispatchRsvpNotifications($event, $guest, $rsvp);

        return $this->redirectThanks($event, $guest, $rsvp);
    }

    public function showOpen(string $slug, InvitationCustomizationService $customizationService): View
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_public', true)
            ->with('invitationTemplate')
            ->firstOrFail();

        if (! $event->isRsvpOpen()) {
            return view('rsvp.closed', ['event' => $event, 'guest' => null]);
        }

        return view('rsvp.open-show', [
            'event' => $event,
            'maxAttendees' => 1,
            'rsvpFormConfig' => $customizationService->resolveRsvpFormConfig($event),
        ]);
    }

    public function storeOpen(
        string $slug,
        StoreOpenRsvpRequest $request,
        RsvpSubmissionService $rsvpSubmissionService,
    ): RedirectResponse {
        $event = $request->resolveEvent();
        if ($event === null) {
            abort(404);
        }

        /** @var array{name:string,email:string,phone?:string|null} $contact */
        $contact = $request->validated();

        try {
            /** @var Guest $guest */
            $guest = Guest::query()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'email' => $contact['email'],
                ],
                [
                    'name' => $contact['name'],
                    'phone' => $contact['phone'] ?? null,
                    'invitation_token' => null,
                    'plus_one_allowed' => false,
                ]
            );
        } catch (QueryException) {
            // Concurrent request won the INSERT race on the unique(event_id, email) constraint.
            // Re-fetch the row that was just created by the other request.
            /** @var Guest $guest */
            $guest = Guest::query()
                ->where('event_id', $event->id)
                ->where('email', $contact['email'])
                ->firstOrFail();
        }

        $guest->fill([
            'name' => $contact['name'],
            'phone' => $contact['phone'] ?? null,
        ])->save();

        $payload = $request->validatedRsvpPayload();

        $rsvp = $rsvpSubmissionService->submit($event, $guest, $payload);

        $this->dispatchRsvpNotifications($event, $guest, $rsvp);

        return $this->redirectThanks($event, $guest, $rsvp);
    }

    /**
     * Flash-only confirmation page for open (no-token) RSVPs — the guest is
     * identified by email, not a persistent token, so there's nothing safe to
     * key a bookmarkable/refreshable URL off of. See thanksByToken() for the
     * richer, refreshable version token guests get instead.
     */
    public function thanks(): View
    {
        $event = session('thanks_event');
        $guest = session('thanks_guest');
        $rsvp = session('thanks_rsvp');

        if (! $event instanceof Event || ! $guest instanceof Guest || ! $rsvp instanceof Rsvp) {
            return view('rsvp.thank-you', ['event' => null, 'guest' => null, 'rsvp' => null]);
        }

        return view('rsvp.thank-you', $this->confirmationViewData($event, $guest, $rsvp, refreshable: false));
    }

    /**
     * Refreshable/bookmarkable confirmation page for token guests — re-queries
     * fresh data every load instead of trusting a one-shot flash, so reopening
     * the link (or "Change RSVP" → resubmit → back) always shows the current
     * response, not whatever was true at the moment of the original submit.
     */
    public function thanksByToken(string $token): View|RedirectResponse
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event', 'rsvp'])
            ->firstOrFail();

        $rsvp = $guest->rsvp;

        // A token is valid the moment the guest exists, but there is nothing to
        // confirm until they've actually submitted once — send them to the form.
        if ($rsvp === null) {
            return redirect()->route('rsvp.token.show', ['token' => $token]);
        }

        return view('rsvp.thank-you', $this->confirmationViewData($guest->event, $guest, $rsvp, refreshable: true));
    }

    private function redirectThanks(Event $event, Guest $guest, Rsvp $rsvp): RedirectResponse
    {
        if ($guest->invitation_token !== null) {
            return redirect()->route('rsvp.token.thanks', ['token' => $guest->invitation_token]);
        }

        // No token to build a fresh-data URL from — flash the models themselves so
        // the very next request (the redirect this method returns) can render a full
        // confirmation. A later refresh/bookmark loses this, same as it always has.
        return redirect()
            ->route('rsvp.thanks')
            ->with('thanks_event', $event)
            ->with('thanks_guest', $guest)
            ->with('thanks_rsvp', $rsvp);
    }

    /**
     * @return array{event: Event, guest: Guest, rsvp: Rsvp, refreshable: bool, showEntryPass: bool, viewInvitationUrl: ?string, changeRsvpUrl: ?string, shareUrl: ?string, hasCalendarWindow: bool}
     */
    private function confirmationViewData(Event $event, Guest $guest, Rsvp $rsvp, bool $refreshable): array
    {
        $hasToken = $guest->invitation_token !== null;

        // A private event has no public page at all (events.public 403s), so a
        // non-token (open) guest there gets no View Invitation/Change/Share links —
        // there genuinely isn't a URL that would work for them.
        $tokenOrPublicShowUrl = $hasToken
            ? route('rsvp.token.show', ['token' => $guest->invitation_token])
            : ($event->is_public ? route('events.public', ['slug' => $event->slug]) : null);

        return [
            'event' => $event,
            'guest' => $guest,
            'rsvp' => $rsvp,
            'refreshable' => $refreshable,
            'showEntryPass' => $guest->hasEntryPassFor($rsvp, $event),
            'viewInvitationUrl' => $tokenOrPublicShowUrl,
            'changeRsvpUrl' => $hasToken
                ? $tokenOrPublicShowUrl
                : ($event->is_public ? route('rsvp.open.show', ['slug' => $event->slug]) : null),
            'shareUrl' => $hasToken
                ? $guest->personalRsvpUrl()
                : ($event->is_public ? route('events.public', ['slug' => $event->slug], absolute: true) : null),
            'hasCalendarWindow' => EventCalendarLinks::window($event) !== null,
        ];
    }

    private function dispatchRsvpNotifications(Event $event, Guest $guest, Rsvp $rsvp): void
    {
        try {
            $rsvp->loadMissing('guest');
            $communication = app(CommunicationService::class);

            if (is_string($guest->email) && $guest->email !== '') {
                $communication->sendRsvpConfirmation($event, $guest, $rsvp);
            }

            $event->loadMissing('user');
            $host = $event->user;

            if ($host !== null && $host->wantsEmailRsvpUpdates()) {
                $communication->notifyHostNewRsvp($host, $event, $guest, $rsvp);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
