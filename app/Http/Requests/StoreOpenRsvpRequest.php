<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRsvpPayload;
use App\Models\Event;
use App\Models\Guest;
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
        $slug = $this->route('slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $event = Event::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_public', true)
            ->first();

        if ($event === null || ! $event->isInvitation()) {
            return null;
        }

        return $event;
    }
}
