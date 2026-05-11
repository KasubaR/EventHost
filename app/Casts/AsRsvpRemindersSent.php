<?php

namespace App\Casts;

use App\Support\RsvpReminderBuckets;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforces {@see RsvpReminderBuckets} when reading/writing `guests.rsvp_reminders_sent`.
 *
 * @implements CastsAttributes<list<string>, mixed>
 */
final class AsRsvpRemindersSent implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return RsvpReminderBuckets::normalize($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $normalized = RsvpReminderBuckets::normalize($value);

        if ($normalized === []) {
            return [$key => null];
        }

        return [$key => json_encode(array_values($normalized), JSON_THROW_ON_ERROR)];
    }
}
