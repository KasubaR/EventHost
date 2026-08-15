<?php

namespace App\Http\Controllers;

use App\Enums\RsvpStatus;
use App\Http\Requests\StoreOpenRsvpRequest;
use App\Http\Requests\StoreRsvpByTokenRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\CommunicationService;
use App\Services\InvitationCustomizationService;
use App\Services\QrCodeService;
use App\Services\RsvpSubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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

        return view('rsvp.token-show', [
            'guest' => $guest,
            'event' => $event,
            'existingRsvp' => $guest->rsvp,
            'maxAttendees' => $event->maxAttendeeSlotsForGuest($guest),
            'rsvpFormConfig' => $customizationService->resolveRsvpFormConfig($event),
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
    public function entryPassQr(string $token, QrCodeService $qrCodeService): Response
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

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * Only a guest who RSVP'd attending gets an entry pass, and only while the
     * host's plan actually supports check-in scanning — showing a QR nobody can
     * scan would just confuse the guest. See plans/guest-entry-pass.md §0.
     */
    private function guestHasEntryPass(Guest $guest, Event $event): bool
    {
        $rsvp = $guest->rsvp;

        return $guest->invitation_token !== null
            && $rsvp !== null
            && $rsvp->status === RsvpStatus::Accepted
            && $event->ownerHasPremiumEventTools();
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

        return $this->redirectThanks($event, $guest);
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

        return $this->redirectThanks($event, $guest);
    }

    public function thanks(): View
    {
        return view('rsvp.thank-you');
    }

    private function redirectThanks(Event $event, Guest $guest): RedirectResponse
    {
        return redirect()
            ->route('rsvp.thanks')
            ->with('thanks_meta', [
                'event_name' => $event->name,
                'guest_name' => $guest->name,
            ]);
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
