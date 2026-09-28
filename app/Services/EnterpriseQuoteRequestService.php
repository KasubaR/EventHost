<?php

namespace App\Services;

use App\Models\EnterpriseQuoteRequest;
use App\Models\User;
use App\Notifications\EnterpriseQuoteRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class EnterpriseQuoteRequestService
{
    public function submit(User $user, ?string $message): EnterpriseQuoteRequest
    {
        $request = DB::transaction(function () use ($user, $message): EnterpriseQuoteRequest {
            // Lock the user row so two concurrent submissions (double-click, two
            // tabs) can't both pass the pending-request check before either
            // insert commits — same invariant, same technique as
            // CustomQuoteService::create().
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (EnterpriseQuoteRequest::pendingFor($user) !== null) {
                throw new \InvalidArgumentException(
                    'You already have a pending Enterprise request — our team will be in touch.'
                );
            }

            return EnterpriseQuoteRequest::query()->create([
                'user_id' => $user->id,
                'message' => $message,
            ]);
        });

        // The persisted row above is the source of truth regardless of what
        // happens here — a failed send must never lose the request the way a
        // failed ContactController send loses that lead entirely.
        try {
            Notification::route('mail', config('mail.support_address'))
                ->notify(new EnterpriseQuoteRequestedNotification($user, $message));
        } catch (\Throwable $e) {
            Log::error('enterprise_quote_request.notify_failed', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $request;
    }

    public function dismiss(EnterpriseQuoteRequest $request): EnterpriseQuoteRequest
    {
        if (! $request->isPending()) {
            throw new \InvalidArgumentException('This request has already been handled.');
        }

        $request->forceFill(['dismissed_at' => now()])->save();

        return $request->fresh();
    }
}
