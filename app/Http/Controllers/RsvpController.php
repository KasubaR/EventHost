<?php

namespace App\Http\Controllers;

use App\Enums\PublicInvitationStatus;
use App\Enums\RsvpStatus;
use App\Http\Requests\StoreOpenRsvpRequest;
use App\Http\Requests\StoreRsvpByTokenRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\CommunicationService;
use App\Services\InvitationCustomizationService;
use App\Services\PublicInvitationResolver;
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
    public function showByToken(
        string $token,
        InvitationCustomizationService $customizationService,
        PublicInvitationResolver $resolver,
    ): View {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with([
                'event' => fn ($q) => $q->withTrashed(),
                'event.invitationTemplate',
                'rsvp',
                'eventTable',
            ])
            ->firstOrFail();

        $event = $guest->event;
        abort_if($event === null, 404);
        abort_unless($event->isInvitation(), 404);

        $lifecycle = $resolver->statusForLoadedEvent($event);
        if ($lifecycle !== null && $lifecycle !== PublicInvitationStatus::Ended) {
            // Cancelled / paused / gone — dedicated status page, not the designed invite.
            // Ended still uses rsvp.closed so accepted guests keep the familiar closed copy.
            return $resolver->statusView($event, $lifecycle);
        }

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
            ->with(['event' => fn ($q) => $q->withTrashed(), 'rsvp'])
            ->first();

        $event = $guest?->event;

        abort_if($guest === null || $event === null || ! $event->isInvitation() || ! $this->guestHasEntryPass($guest, $event), 404);

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
     * PNG sibling of entryPassQr() — WhatsApp (and email-style clients) need raster
     * media. Twilio fetches this absolute URL when attaching the entry pass after
     * an Accepted WhatsApp RSVP. Same eligibility gate as the SVG route.
     */
    public function entryPassQrPng(string $token, QrCodeService $qrCodeService): Response
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event' => fn ($q) => $q->withTrashed(), 'rsvp'])
            ->first();

        $event = $guest?->event;

        abort_if($guest === null || $event === null || ! $event->isInvitation() || ! $this->guestHasEntryPass($guest, $event), 404);

        $url = $guest->checkInQrUrl();
        abort_if($url === null, 404);

        $png = Cache::remember(
            'guest-entry-pass-qr-png:'.$token,
            now()->addWeek(),
            fn () => $qrCodeService->png($url)
        );

        return response($png, 200, ['Content-Type' => 'image/png']);
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
            ->with(['event' => fn ($q) => $q->withTrashed()])
            ->firstOrFail();

        $event = $guest->event;
        // StoreRsvpByTokenRequest::authorize() already refuses a null/closed
        // event before this runs — this guard is defense in depth, not the
        // primary gate.
        abort_if($event === null || ! $event->isInvitation(), 404);

        $payload = $request->validatedRsvpPayload();

        $rsvp = $rsvpSubmissionService->submit($event, $guest, $payload);

        $this->dispatchRsvpNotifications($event, $guest, $rsvp);

        return $this->redirectThanks($event, $guest, $rsvp);
    }

    public function showOpen(string $slug, InvitationCustomizationService $customizationService, PublicInvitationResolver $resolver): View|RedirectResponse
    {
        $resolved = $resolver->resolveOpenRsvp($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved['event'];
        $status = $resolved['status'];

        if ($status !== null) {
            if ($status === PublicInvitationStatus::Ended) {
                return view('rsvp.closed', ['event' => $event, 'guest' => null]);
            }

            return $resolver->statusView($event, $status);
        }

        $event->loadMissing('invitationTemplate');

        if (! $event->isRsvpOpen()) {
            return view('rsvp.closed', ['event' => $event, 'guest' => null]);
        }

        // The guest-list cap is a private-event-only concern here — a public/
        // free-registration signup was never capacity-limited by this form. See
        // StoreOpenRsvpRequest::authorize() for the matching write-side check.
        if (! $event->is_public && $event->hasReachedGuestCapacity()) {
            return view('rsvp.closed', ['event' => $event, 'guest' => null, 'guestListFull' => true]);
        }

        return view('rsvp.open-show', [
            'event' => $event,
            'maxAttendees' => (! $event->is_public && $event->allow_plus_one) ? 2 : 1,
            'rsvpFormConfig' => $customizationService->resolveRsvpFormConfig($event),
            'isPrivateOpenRsvp' => ! $event->is_public,
            'preselectedStatus' => RsvpStatus::tryFrom(strtolower(trim((string) request()->query('status', '')))),
        ]);
    }

    public function storeOpen(
        string $slug,
        StoreOpenRsvpRequest $request,
        RsvpSubmissionService $rsvpSubmissionService,
        CommunicationService $communicationService,
    ): RedirectResponse {
        $event = $request->resolveEvent();
        if ($event === null) {
            abort(404);
        }

        // A private event has no per-guest invite already issued, so a guest who
        // self-RSVPs here needs a real invitation_token to get an entry pass and a
        // personal "view/change RSVP" link back — same as a guest the host added
        // by hand. A public/free-registration signup stays token-less, unchanged:
        // that flow is closer to an anonymous headcount than a guest list.
        $isPrivate = ! $event->is_public;

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
                    'invitation_token' => $isPrivate ? Str::random(48) : null,
                    'plus_one_allowed' => $isPrivate && (bool) $event->allow_plus_one,
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

        $data = ['name' => $contact['name'], 'phone' => $contact['phone'] ?? null];

        // A returning guest who first came through this link already has a token
        // (branch above set one); a guest who existed beforehand from some other
        // path (e.g. the public open-RSVP form, before this event became private —
        // audience is otherwise immutable) did not — back-fill one now so the
        // "you get a personal link" promise the private form makes always holds.
        if ($isPrivate && $guest->invitation_token === null) {
            $data['invitation_token'] = Str::random(48);
        }

        $guest->fill($data)->save();

        $payload = $request->validatedRsvpPayload();

        $rsvp = $rsvpSubmissionService->submit($event, $guest, $payload);

        $this->dispatchRsvpNotifications($event, $guest, $rsvp);

        // WhatsApp delivery of the personal link is a Pro+ perk everywhere else on
        // this page (GuestController::sendWhatsAppInvitation() — a real per-message
        // cost), so an automatic send here follows the same gate rather than giving
        // every plan a free way around it. Every plan still gets the email above.
        if ($isPrivate && $event->ownerHasPremiumEventTools()) {
            try {
                $communicationService->sendWhatsAppInvitation($event, $guest);
            } catch (\Throwable $e) {
                report($e);
            }
        }

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
    public function thanksByToken(string $token, PublicInvitationResolver $resolver): View|RedirectResponse
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event' => fn ($q) => $q->withTrashed(), 'rsvp'])
            ->firstOrFail();

        $event = $guest->event;
        abort_if($event === null, 404);

        $rsvp = $guest->rsvp;

        // A token is valid the moment the guest exists, but there is nothing to
        // confirm until they've actually submitted once — send them to the form.
        if ($rsvp === null) {
            return redirect()->route('rsvp.token.show', ['token' => $token]);
        }

        // Cancelled / paused / gone since they RSVP'd — same dedicated status page
        // showByToken() shows. Ended still renders their receipt below: it's a
        // record of what they already submitted, not the invitation itself.
        $lifecycle = $resolver->statusForLoadedEvent($event);
        if ($lifecycle !== null && $lifecycle !== PublicInvitationStatus::Ended) {
            return $resolver->statusView($event, $lifecycle);
        }

        return view('rsvp.thank-you', $this->confirmationViewData($event, $guest, $rsvp, refreshable: true));
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

        // storeOpen() always issues a token for a private event now (see its own
        // comment), so a token-less guest here only exists on an is_public event
        // (the free-registration flow deliberately stays token-less) or predates
        // that change — either way there's no personal link to build, so fall back
        // to the slug URL only when the event is actually public.
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
        app(CommunicationService::class)->dispatchRsvpNotifications($event, $guest, $rsvp);
    }
}
