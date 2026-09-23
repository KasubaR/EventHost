<?php

namespace App\Casts;

use App\Support\WhatsAppEventReminderBuckets;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforces {@see WhatsAppEventReminderBuckets} on `guests.whatsapp_event_reminders_sent`.
 *
 * @implements CastsAttributes<list<string>, mixed>
 */
final class AsWhatsAppEventRemindersSent implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return WhatsAppEventReminderBuckets::normalize($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $normalized = WhatsAppEventReminderBuckets::normalize($value);

        if ($normalized === []) {
            return [$key => null];
        }

        return [$key => json_encode(array_values($normalized), JSON_THROW_ON_ERROR)];
    }
}
