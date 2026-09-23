<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PublicInvitationStatus;
use App\Http\Requests\Concerns\ValidatesRsvpPayload;
use App\Models\Event;
use App\Models\Guest;
use App\Services\PublicInvitationResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

/**
 * API twin of StoreOpenRsvpRequest. Gates through PublicInvitationResolver::resolveOpenRsvp()
 * instead of restating that class's narrower duplicate query (StoreOpenRsvpRequest::resolveEvent()
 * only checks is_published/is_public/cancelled_at/invitation_paused_at — no slug-redirect
 * handling, no distinction between cancelled/paused/gone/ended), so a soft-status event gets the
 * resolver's real outcome instead of a blanket 404. This is a new class rather than an edit to
 * the tested web request — restating the field rules here is cheap and carries zero regression
 * risk to the web flow. See the Slice B1 plan, design decision #3.
 */
class StoreOpenRsvpApiRequest extends FormRequest
{
    use ValidatesRsvpPayload;

    /**
     * @var array{event: Event, status: ?PublicInvitationStatus}|RedirectResponse|null
     */
    private array|RedirectResponse|null $resolved = null;

    public function authorize(): bool
    {
        $resolved = $this->resolvedOpenRsvp();

        if ($resolved instanceof RedirectResponse) {
            // Not a 403 here — the controller returns the redirect itself, before this
            // class's validation could ever matter.
            return true;
        }

        if ($resolved['status'] !== null || ! $resolved['event']->isRsvpOpen()) {
            abort(403);
        }

        // PublicInvitationResolver::resolveOpenRsvp() allows a private event
        // through now (the web open-RSVP form gained a private-event mode with a
        // real invitation_token, phone requirement and guest-capacity check — see
        // RsvpController::storeOpen()). This API endpoint hasn't grown that
        // parallel behavior, so it keeps requiring is_public rather than silently
        // exposing a weaker, unprotected version of the private flow.
        if (! $resolved['event']->is_public) {
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
            'phone' => ['nullable', 'string', 'max:50'],
        ], $this->rsvpFieldRules($event, plusOneAllowed: false));
    }

    public function resolveEvent(): ?Event
    {
        $resolved = $this->resolvedOpenRsvp();

        return $resolved instanceof RedirectResponse ? null : $resolved['event'];
    }

    /**
     * @return array{event: Event, status: ?PublicInvitationStatus}|RedirectResponse
     */
    public function resolvedOpenRsvp(): array|RedirectResponse
    {
        if ($this->resolved === null) {
            $slug = $this->route('slug');
            $this->resolved = app(PublicInvitationResolver::class)
                ->resolveOpenRsvp(is_string($slug) ? $slug : '');
        }

        return $this->resolved;
    }
}
