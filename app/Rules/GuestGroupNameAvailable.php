<?php

namespace App\Rules;

use App\Models\Event;
use App\Models\GuestGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether no other group of the event already has this name, compared as `GuestGroup::nameKey()` (cleaned, lower-cased), so the
 * check agrees with GuestGroupNamer on SQLite and on MySQL. This is the early, friendly refusal; GuestGroupNamer repeats it under the
 * event lock. plans/guest-groups-gaps.md Phase 3.
 */
class GuestGroupNameAvailable implements ValidationRule
{
    public function __construct(
        private readonly Event $event,
        private readonly ?int $ignoreGroupId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $key = GuestGroup::nameKey($value);

        $taken = GuestGroup::query()
            ->where('event_id', $this->event->id)
            ->when($this->ignoreGroupId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreGroupId))
            ->pluck('name')
            ->contains(fn (string $existing) => GuestGroup::nameKey($existing) === $key);

        if ($taken) {
            $fail('The name has already been taken.');
        }
    }
}
