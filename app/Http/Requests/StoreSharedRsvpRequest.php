<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRsvpPayload;
use App\Models\Event;
use App\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSharedRsvpRequest extends FormRequest
{
    use ValidatesRsvpPayload;

    public function authorize(): bool
    {
        $event = $this->resolveEvent();
        if ($event === null) {
            abort(404);
        }

        if (! $event->isRsvpOpen()) {
            abort(403);
        }

        // The plan's guest-list cap applies to this link exactly as it does to a
        // guest the host adds by hand (Event::guestCapacity()) — but only for a
        // genuinely new signup. A returning guest updating their own RSVP by
        // resubmitting with the same email must never be blocked by a cap that
        // was reached by other guests after their first submission.
        $email = is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : null;
        $isReturningGuest = $email !== null && Guest::query()
            ->where('event_id', $event->id)
            ->where('email', $email)
            ->exists();

        if (! $isReturningGuest && $event->hasReachedGuestCapacity()) {
            abort(403);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if (is_string($status)) {
            $this->merge(['status' => strtolower(trim($status))]);
        }

        $email = $this->input('email');
        if (is_string($email)) {
            $trimmed = strtolower(trim($email));
            $this->merge(['email' => $trimmed === '' ? null : $trimmed]);
        }

        foreach (['phone', 'name'] as $field) {
            $v = $this->input($field);
            if (is_string($v)) {
                $t = trim($v);
                $this->merge([$field => $t === '' ? null : $t]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $event = $this->resolveEvent();
        if ($event === null) {
            return [];
        }

        $existingGuestId = Guest::query()
            ->where('event_id', $event->id)
            ->where('email', $this->input('email'))
            ->value('id');

        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('guests', 'email')
                    ->where(fn ($q) => $q->where('event_id', $event->id))
                    ->ignore($existingGuestId),
            ],
            // Required (unlike the public open-RSVP form's optional phone) — the
            // whole point of this link is that the guest gets their personal
            // invitation link back, and WhatsApp is one of the two channels that
            // delivers it.
            'phone' => ['required', 'string', 'max:50'],
        ], $this->rsvpFieldRules($event, plusOneAllowed: true));
    }

    public function resolveEvent(): ?Event
    {
        $token = $this->route('token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        $event = Event::query()
            ->where('open_rsvp_token', $token)
            ->where('is_published', true)
            ->whereNull('cancelled_at')
            ->whereNull('invitation_paused_at')
            ->first();

        if ($event === null || ! $event->isInvitation()) {
            return null;
        }

        return $event;
    }
}
