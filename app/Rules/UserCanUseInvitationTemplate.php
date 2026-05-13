<?php

namespace App\Rules;

use App\Models\InvitationTemplate;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UserCanUseInvitationTemplate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            $fail('You must be signed in to choose a template.');

            return;
        }

        $template = InvitationTemplate::query()
            ->whereKey($value)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            return;
        }

        if (! $user->canUseInvitationTemplate($template)) {
            $fail(
                $user->isActive()
                    ? 'This template requires '.$template->requiredTier()->label().'.'
                    : 'An active subscription is required to use a template.'
            );
        }
    }
}
