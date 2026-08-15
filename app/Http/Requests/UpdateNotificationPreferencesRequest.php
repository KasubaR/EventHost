<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Preferences only. Nothing here can touch a profile column, so a stray
     * name/email in the payload is ignored rather than saved.
     *
     * @return array<string, array<int, ValidationRule|string>|string>
     */
    public function rules(): array
    {
        $rules = ['notification_preferences' => ['sometimes', 'array']];

        foreach (array_keys(User::defaultNotificationPreferences()) as $key) {
            $rules['notification_preferences.'.$key] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * The submitted toggles, narrowed to known preference keys. An `array`
     * rule validates the whole attribute, so unknown keys survive validation —
     * they are dropped here instead.
     *
     * @return array<string, bool>
     */
    public function preferences(): array
    {
        $submitted = array_intersect_key(
            $this->safe()->array('notification_preferences'),
            User::defaultNotificationPreferences()
        );

        return array_map(static fn ($value): bool => (bool) $value, $submitted);
    }
}
