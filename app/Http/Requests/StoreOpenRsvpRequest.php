<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRsvpPayload;
use App\Models\Event;
use App\Models\Guest;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpenRsvpRequest extends FormRequest
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

        // The plan's guest-list cap only matters for a private event here — a
        // public/free-registration signup was never capacity-limited by this
        // form, and that stays unchanged. It applies to this link exactly as it
        // does to a guest the host adds by hand (Event::guestCapacity()), but
        // only for a genuinely new signup: a returning guest resubmitting their
        // own RSVP with the same email must never be blocked by a cap that was
        // reached by other guests after their first submission.
        if (! $event->is_public) {
            $email = is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : null;
            $isReturningGuest = $email !== null && Guest::query()
                ->where('event_id', $event->id)
                ->where('email', $email)
                ->exists();

            if (! $isReturningGuest && $event->hasReachedGuestCapacity()) {
                abort(403);
            }
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
            // Required for a private event: the whole point of this link there is
            // that the guest gets a personal invitation link back, and WhatsApp is
            // one of the two channels that delivers it (RsvpController::storeOpen()).
            // A public/free-registration signup keeps phone optional, unchanged.
            'phone' => array_merge(
                $event->is_public ? ['nullable', 'string', 'max:50'] : ['required', 'string', 'max:50'],
                // The duplicate-phone check below only makes sense for a private
                // event's real guest list — a public/free-registration signup is
                // token-less by design (a headcount, not a guest list; see the
                // comment on $isPrivate in RsvpController::storeOpen()), so there
                // is no "personal link" to point a match back to.
                $event->is_public ? [] : [
                    // A phone matching a DIFFERENT guest (the email above didn't
                    // already resolve to them) means someone is likely already on
                    // the list under this number — block rather than silently
                    // spinning up a second guest row for the same person. Never
                    // hands back that guest's own link here: the phone in this
                    // request is unverified, typed by whoever is submitting, so
                    // revealing another guest's personal RSVP link to it would be
                    // a privacy leak. They're pointed back to the link already
                    // promised on this form (see rsvp/open-show.blade.php).
                    function (string $attribute, mixed $value, Closure $fail) use ($event, $existingGuestId): void {
                        if (Guest::matchingPhone($event, is_string($value) ? $value : null, ignoreGuestId: $existingGuestId) !== null) {
                            $fail('This phone number is already on the guest list. If that\'s you, use the personal link we emailed you to view or update your RSVP.');
                        }
                    },
                ]
            ),
        ], $this->rsvpFieldRules($event, plusOneAllowed: ! $event->is_public));
    }

    public function resolveEvent(): ?Event
    {
        $slug = $this->route('slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $event = Event::query()
            ->where('slug', $slug)
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
