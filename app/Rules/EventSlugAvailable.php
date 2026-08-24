<?php

namespace App\Rules;

use App\Services\EventSlugService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EventSlugAvailable implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreEventId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $slug = strtolower(trim($value));
        $service = app(EventSlugService::class);

        if (strlen($slug) < EventSlugService::MIN_LENGTH || strlen($slug) > EventSlugService::MAX_LENGTH) {
            $fail('The custom URL must be between '.EventSlugService::MIN_LENGTH.' and '.EventSlugService::MAX_LENGTH.' characters.');

            return;
        }

        if (! preg_match(EventSlugService::PATTERN, $slug)) {
            $fail('Use lowercase letters, numbers, and hyphens only (e.g. john-mary).');

            return;
        }

        if (! $service->isAvailable($slug, $this->ignoreEventId)) {
            $fail('That custom URL is already taken.');
        }
    }
}
