<?php

namespace App\Services;

use App\Enums\CustomQuoteStatus;
use App\Models\Admin;
use App\Models\CustomQuote;
use App\Models\User;
use App\Notifications\CustomQuoteReadyNotification;
use Illuminate\Support\Facades\DB;

class CustomQuoteService
{
    /**
     * @param  array{amount: mixed, credits_granted: mixed, note?: string|null}  $data
     */
    public function create(User $user, Admin $admin, array $data): CustomQuote
    {
        $quote = DB::transaction(function () use ($user, $admin, $data): CustomQuote {
            // Lock the user row so two concurrent admin submissions (double-click,
            // two admins on the same ticket) can't both pass the pending-quote
            // check before either insert commits — there is no unique DB
            // constraint on (user_id, status) backing this invariant.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = CustomQuote::query()
                ->where('user_id', $user->id)
                ->pending()
                ->exists();

            if ($existing) {
                throw new \InvalidArgumentException('This user already has a pending custom quote. Update or cancel it first.');
            }

            return CustomQuote::query()->create([
                'user_id' => $user->id,
                'amount' => $data['amount'],
                'credits_granted' => (int) $data['credits_granted'],
                'note' => $data['note'] ?? null,
                'status' => CustomQuoteStatus::Pending,
                'created_by' => $admin->id,
            ]);
        });

        $user->notify(new CustomQuoteReadyNotification($quote));
        $quote->forceFill(['notified_at' => now()])->save();

        return $quote->fresh();
    }

    /**
     * @param  array{amount: mixed, credits_granted: mixed, note?: string|null}  $data
     */
    public function update(CustomQuote $quote, array $data): CustomQuote
    {
        if (! $quote->isPending()) {
            throw new \InvalidArgumentException('Only a pending custom quote can be updated.');
        }

        $quote->forceFill([
            'amount' => $data['amount'],
            'credits_granted' => (int) $data['credits_granted'],
            'note' => $data['note'] ?? null,
        ])->save();

        $quote->user?->notify(new CustomQuoteReadyNotification($quote));
        $quote->forceFill(['notified_at' => now()])->save();

        return $quote->fresh();
    }

    public function cancel(CustomQuote $quote): CustomQuote
    {
        if (! $quote->isPending()) {
            throw new \InvalidArgumentException('Only a pending custom quote can be cancelled.');
        }

        $quote->forceFill([
            'status' => CustomQuoteStatus::Cancelled,
        ])->save();

        return $quote->fresh();
    }
}
