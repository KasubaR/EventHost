<?php

namespace App\Http\Requests;

use App\Enums\HelpRequestKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHelpRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>|string>
     */
    public function rules(): array
    {
        $kind = $this->input('kind');

        return [
            'kind' => ['required', Rule::enum(HelpRequestKind::class)],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'contact_preference' => ['nullable', 'string', 'max:255'],
            // Only the client's own events — a request can never name somebody else's.
            'event_id' => [
                $kind === HelpRequestKind::EditEvent->value ? 'required' : 'nullable',
                'integer',
                Rule::exists('events', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Only an "edit an event" request is about one event; for the others it is ignored.
     *
     * @return array{kind: string, message: string, event_id: int|null, contact_preference: string|null}
     */
    public function helpRequestData(): array
    {
        $data = $this->validated();

        return [
            'kind' => $data['kind'],
            'message' => $data['message'],
            'contact_preference' => $data['contact_preference'] ?? null,
            'event_id' => $data['kind'] === HelpRequestKind::EditEvent->value ? (int) $data['event_id'] : null,
        ];
    }
}
