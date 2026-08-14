<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => [
                'required',
                'integer',
                Rule::exists('events', 'id')->where(
                    fn ($query) => $query->where('user_id', $this->user()?->id)
                ),
            ],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'min:20', 'max:1500'],
        ];
    }

    /**
     * The `exists` rule above proves the event is the host's own. This second
     * pass answers the separate question of whether it may be reviewed *yet*,
     * with a message that says which condition failed.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('event_id')) {
                    return;
                }

                $event = Event::query()->find($this->integer('event_id'));

                if ($event === null || $event->isReviewable()) {
                    return;
                }

                $validator->errors()->add('event_id', $this->reasonEventCannotBeReviewed($event));
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_id.exists' => 'Pick one of your own events.',
            'body.min' => 'Tell us a little more — at least 20 characters.',
        ];
    }

    private function reasonEventCannotBeReviewed(Event $event): string
    {
        if ($event->review !== null) {
            return 'You have already reviewed this event.';
        }

        if (! $event->is_published) {
            return 'Only published events can be reviewed.';
        }

        return 'You can review this event once it has taken place.';
    }
}
