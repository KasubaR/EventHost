<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuestBulkActionRequest extends FormRequest
{
    /** The most guests one bulk action may name (plans/guest-groups-gaps.md Phase 4). A list page holds 30; this leaves room for scripted callers. */
    public const MAX_GUESTS = 500;

    protected function prepareForValidation(): void
    {
        $gid = $this->input('guest_group_id');
        if ($gid === '' || $gid === null) {
            $this->merge(['guest_group_id' => null]);
        }

        $tid = $this->input('event_table_id');
        if ($tid === '' || $tid === null) {
            $this->merge(['event_table_id' => null]);
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
                'assign_table',
                'mark_sent',
                'delete',
                'send_reminder_email',
                'send_update_email',
                'prepare_whatsapp_share',
                'allow_plus_one',
            ])],
            'guest_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_GUESTS],
            // Membership is one query in after(), not one `exists` query per id.
            'guest_ids.*' => ['integer'],
            'guest_group_id' => [
                'nullable',
                'integer',
                Rule::exists('guest_groups', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'event_table_id' => [
                'nullable',
                'integer',
                Rule::exists('event_tables', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'days_until' => ['nullable', Rule::in([1, 3, 7])],
            'update_message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['guest_ids.max' => 'Select up to '.self::MAX_GUESTS.' guests at a time.'];
    }

    /**
     * Every selected guest belongs to this event, checked with a single count.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $ids = $this->input('guest_ids');
            if ($validator->errors()->has('guest_ids') || $validator->errors()->has('guest_ids.*') || ! is_array($ids)) {
                return;
            }

            /** @var Event $event */
            $event = $this->route('event');
            $wanted = array_values(array_unique(array_map('intval', $ids)));

            if (Guest::query()->where('event_id', $event->id)->whereIn('id', $wanted)->count() !== count($wanted)) {
                $validator->errors()->add('guest_ids', 'Some of the selected guests are not on this event\'s list. Reload the page and try again.');
            }
        }];
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
