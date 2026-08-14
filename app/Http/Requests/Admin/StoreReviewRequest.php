<?php

namespace App\Http\Requests\Admin;

use App\Support\InvitationVideoBackground;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-authored video reviews. Hosts never reach this — their submissions go
 * through App\Http\Requests\StoreReviewRequest and are always text.
 */
class StoreReviewRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:1500'],
            'author_name' => ['required', 'string', 'max:255'],
            'author_context' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'video_ref' => [
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (InvitationVideoBackground::parseVideoId((string) $value) === null) {
                        $fail('Enter a valid YouTube link or video ID.');
                    }
                },
            ],
            'video_poster' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'author_photo' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'is_featured' => ['required', 'boolean'],
            'featured_sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }
}
