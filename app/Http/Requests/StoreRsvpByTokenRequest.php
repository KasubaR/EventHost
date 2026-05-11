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

        $guest->loadMissing('event');

        if (! $guest->event->isRsvpOpen()) {
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
