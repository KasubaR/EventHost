<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRsvpPayload;
use App\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;

class StoreRsvpByTokenRequest extends FormRequest
{
    use ValidatesRsvpPayload;

    public function authorize(): bool
    {
        $guest = $this->guestFromToken();
        if ($guest === null) {
            abort(404);
        }

        $event = $guest->event;

        // Guest::event() excludes soft-deleted events by default, so a guest
        // whose event was deleted resolves to a null relation here rather than
        // isRsvpOpen() ever seeing it. Treat that the same as "not open".
        if ($event === null || ! $event->isRsvpOpen()) {
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $guest = $this->guestFromToken();
        if ($guest === null) {
            return [];
        }

        $guest->loadMissing('event');
        $event = $guest->event;
        if ($event === null) {
            return [];
        }

        return $this->rsvpFieldRules($event, $guest->plus_one_allowed);
    }

    private function guestFromToken(): ?Guest
    {
        $token = $this->route('token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        return Guest::query()->where('invitation_token', $token)->first();
    }
}
