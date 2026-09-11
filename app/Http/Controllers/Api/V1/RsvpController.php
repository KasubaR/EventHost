<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOpenRsvpApiRequest;
use App\Http\Requests\StoreRsvpByTokenRequest;
use App\Http\Resources\Api\V1\RsvpFormResource;
use App\Http\Resources\Api\V1\RsvpResource;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\CommunicationService;
use App\Services\InvitationCustomizationService;
use App\Services\PublicInvitationResolver;
use App\Services\RsvpSubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * JSON sibling of App\Http\Controllers\RsvpController — no session, no redirect+flash, no
 * Blade view. Reuses the same FormRequest field-validation, RsvpSubmissionService and
 * PublicInvitationResolver gates the web controller uses. See the Slice B1 plan for the
 * full rationale (why storeByToken/storeOpen return the confirmation body directly instead
 * of a redirect, why the entry-pass QR is a JSON URL field instead of a binary endpoint).
 * The web RsvpController is untouched by this class.
 */
class RsvpController extends Controller
{
    public function showByToken(string $token, PublicInvitationResolver $resolver): RsvpResource|JsonResponse
    {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event' => fn ($q) => $q->withTrashed(), 'rsvp'])
            ->firstOrFail();

        $event = $guest->event;
        abort_if($event === null || ! $event->isInvitation(), 404);

        $lifecycle = $resolver->statusForLoadedEvent($event);
        if ($lifecycle !== null && $lifecycle !== PublicInvitationStatus::Ended) {
            return response()->json([
                'status' => $lifecycle->value,
                'title' => $lifecycle->title(),
                'message' => $lifecycle->message(),
            ]);
        }

        $showEntryPass = $this->guestHasEntryPass($guest, $event);

        if (! $event->isRsvpOpen()) {
            // A guest who already said yes still needs their pass in the days between the
            // RSVP deadline and the event itself; once the event is over there is nothing
            // left to show it for. Same carve-out as the web rsvp.closed view.
            return new RsvpResource(
                $event,
                $guest,
                $guest->rsvp,
                $event->maxAttendeeSlotsForGuest($guest),
                $showEntryPass && ! $event->isLocked(),
            );
        }

        return new RsvpResource(
            $event,
            $guest,
            $guest->rsvp,
            $event->maxAttendeeSlotsForGuest($guest),
            $showEntryPass,
        );
    }

    public function storeByToken(
        string $token,
        StoreRsvpByTokenRequest $request,
        RsvpSubmissionService $rsvpSubmissionService,
    ): RsvpResource {
        $guest = Guest::query()
            ->where('invitation_token', $token)
            ->with(['event' => fn ($q) => $q->withTrashed()])
            ->firstOrFail();

        $event = $guest->event;
        // StoreRsvpByTokenRequest::authorize() already refuses a null/closed event before
        // this runs — this guard is defense in depth, not the primary gate.
        abort_if($event === null || ! $event->isInvitation(), 404);

        $payload = $request->validatedRsvpPayload();

        $rsvp = $rsvpSubmissionService->submit($event, $guest, $payload);

        $this->dispatchRsvpNotifications($event, $guest, $rsvp);

        return new RsvpResource(
            $event,
            $guest,
            $rsvp,
            $event->maxAttendeeSlotsForGuest($guest),
            $this->guestHasEntryPass($guest, $event),
        );
    }

    public function showOpen(
        string $slug,
        InvitationCustomizationService $customizationService,
        PublicInvitationResolver $resolver,
    ): RsvpFormResource|RedirectResponse {
        $resolved = $resolver->resolveOpenRsvp($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved['event'];
        $status = $resolved['status'];

        $event->loadMissing('invitationTemplate');

        return new RsvpFormResource(
            $event,
            $customizationService->resolveRsvpFormConfig($event),
            $event->isRsvpOpen(),
            $status,
        );
    }

    public function storeOpen(
        string $slug,
        StoreOpenRsvpApiRequest $request,
        RsvpSubmissionService $rsvpSubmissionService,
    ): JsonResponse|RedirectResponse {
        $resolved = $request->resolvedOpenRsvp();

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved['event'];

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
            // Concurrent request won the INSERT race on the unique(event_id, email)
            // constraint. Re-fetch the row that was just created by the other request.
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

        // Open RSVP never gets a plus-one, so max_attendees is always 1 — same hardcoded
        // value the web showOpen() passes its view. Forced to 200 explicitly: when this is
        // the guest's first-ever submission, firstOrCreate() just created a new Guest row,
        // and JsonResource's automatic status calculation reads wasRecentlyCreated off
        // whatever model it wraps — that's the Guest row's creation, not the RSVP's, so
        // left alone it would report 201 for a "new guest, same as always" response and
        // 200 for a returning one. Per the Slice B1 plan, this response is always 200.
        return (new RsvpResource(
            $event,
            $guest,
            $rsvp,
            1,
            $this->guestHasEntryPass($guest, $event),
        ))->response()->setStatusCode(200);
    }

    /**
     * Only a guest who RSVP'd attending gets an entry pass, and only while the host's plan
     * actually supports check-in scanning. Copied verbatim from the web RsvpController
     * rather than shared/extracted — matches this codebase's established idiom of
     * restating short gate chains per class (see PublicInvitationResolver's own sibling
     * methods).
     */
    private function guestHasEntryPass(Guest $guest, Event $event): bool
    {
        $rsvp = $guest->rsvp;

        return $rsvp !== null && $guest->hasEntryPassFor($rsvp, $event);
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
