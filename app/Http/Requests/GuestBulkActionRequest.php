<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuestBulkActionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $gid = $this->input('guest_group_id');
        if ($gid === '' || $gid === null) {
            $this->merge(['guest_group_id' => null]);
        }
    }

    public function authorize(): bool
    {
        /** @var Event $event */
        $event = $this->route('event');

        return $this->user() !== null && $this->user()->id === $event->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return [
            'action' => ['required', Rule::in([
                'assign_group',
                'mark_sent',
                'delete',
                'send_reminder_email',
                'send_update_email',
                'prepare_whatsapp_share',
            ])],
            'guest_ids' => ['required', 'array', 'min:1'],
            'guest_ids.*' => [
                'integer',
                Rule::exists('guests', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'guest_group_id' => [
                'nullable',
                'integer',
                Rule::exists('guest_groups', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'days_until' => ['nullable', Rule::in([1, 3, 7])],
            'update_message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes('update_message', ['required'], function (): bool {
            return $this->input('action') === 'send_update_email';
        });

        $validator->sometimes('days_until', ['required'], function (): bool {
            return $this->input('action') === 'send_reminder_email';
        });
    }
}
