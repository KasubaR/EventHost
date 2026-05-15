<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOpenRsvpRequest;
use App\Http\Requests\StoreRsvpByTokenRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\CommunicationService;
use App\Services\InvitationCustomizationService;
use App\Services\RsvpSubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RsvpController extends Controller
{
    public function showByToken(string $token, InvitationCustomizationService $customizationService): View
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event', 'event.invitationTemplate'])
            ->firstOrFail();

        $event = $guest->event;

        if (! $event->isRsvpOpen()) {
            return view('rsvp.closed', ['event' => $event, 'guest' => $guest]);
        }

        $guest->load('rsvp');

        return view('rsvp.token-show', [
            'guest'          => $guest,
            'event'          => $event,
            'existingRsvp'   => $guest->rsvp,
            'maxAttendees'   => $event->maxAttendeeSlotsForGuest($guest),
            'rsvpFormConfig' => $customizationService->resolveRsvpFormConfig($event),
        ]);
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
            'event'          => $event,
            'maxAttendees'   => 1,
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
