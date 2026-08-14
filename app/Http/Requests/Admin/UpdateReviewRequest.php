<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReviewStatus;
use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReviewStatus::class)],
            'moderation_note' => [
                Rule::requiredIf($this->input('status') === ReviewStatus::Rejected->value),
                'nullable',
                'string',
                'max:500',
            ],
            'body' => ['required', 'string', 'max:1500'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'author_name' => ['required', 'string', 'max:255'],
            'author_context' => ['nullable', 'string', 'max:255'],
            'is_featured' => ['required', 'boolean'],
            'featured_sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Review|null $review */
                $review = $this->route('review');

                // Mirrors "a template cannot be featured without an image": the
                // homepage scope filters these out too, so catching it here is
                // the difference between a clear error and a silent no-op.
                if ($review === null || ! $review->isVideo()) {
                    return;
                }

                $hasVideo = is_string($review->video_ref) && $review->video_ref !== '';

                if ($this->boolean('is_featured') && ! $hasVideo) {
                    $validator->errors()->add(
                        'is_featured',
                        'A video review needs a video before it can go on the homepage.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'moderation_note.required' => 'Give the host a reason — they see this note on their reviews page.',
        ];
    }
}
