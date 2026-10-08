<?php

namespace App\Http\Requests;

use App\Exceptions\RsvpClosedException;
use App\Exceptions\RsvpUnavailableException;
use App\Http\Requests\Concerns\ValidatesRsvpPayload;
use App\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;

class StoreRsvpByTokenRequest extends FormRequest
{
    use ValidatesRsvpPayload;

    private ?Guest $resolvedGuest = null;

    private bool $guestResolved = false;

    public function authorize(): bool
    {
        $guest = $this->guest();
        if ($guest === null) {
            abort(404);
        }

        $event = $guest->event;

        // Deleted, or no longer published: say so on the invitation's own page (RsvpUnavailableException),
        // not with a bare 403 whose error page talks about expired verification links. The event is loaded
        // with trashed rows (guest()), so a deleted event is told apart from a missing guest.
        if ($event === null || $event->trashed() || ! $event->is_published) {
            throw new RsvpUnavailableException;
        }

        // Closed is not a bare 403: the guest is sent to the closed page with a message (and, if they
        // already answered, the chance to cancel or reduce). The service re-checks under the lock.
        if (! $event->acceptsRsvpSubmissions() && ! $event->canReduceRsvp($guest->rsvp)) {
            throw new RsvpClosedException(event: $event);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if (is_string($status)) {
            $this->merge(['status' => strtolower(trim($status))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $guest = $this->guest();
        if ($guest === null) {
            return [];
        }

        $event = $guest->event;
        if ($event === null) {
            return [];
        }

        return $this->rsvpFieldRules($event, $guest->plus_one_allowed, $guest->rsvp?->heldSeats() ?? 0);
    }

    /**
     * The guest this link belongs to, looked up once per request and shared with the controller (it used
     * to be queried three times: authorize, rules and the controller). The event comes with trashed rows
     * and the RSVP is eager-loaded; null when the token matches nobody.
     */
    public function guest(): ?Guest
    {
        if (! $this->guestResolved) {
            $this->guestResolved = true;
            $token = $this->route('token');

            $this->resolvedGuest = is_string($token) && $token !== ''
                ? Guest::query()
                    ->where('invitation_token', $token)
                    ->with(['event' => fn ($q) => $q->withTrashed(), 'rsvp'])
                    ->first()
                : null;
        }

        return $this->resolvedGuest;
    }
}
