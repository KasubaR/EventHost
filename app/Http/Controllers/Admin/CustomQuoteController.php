<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomQuoteRequest;
use App\Http\Requests\Admin\UpdateCustomQuoteRequest;
use App\Models\Admin;
use App\Models\CustomQuote;
use App\Models\User;
use App\Services\CustomQuoteService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;

class CustomQuoteController extends Controller
{
    public function store(
        StoreCustomQuoteRequest $request,
        User $user,
        CustomQuoteService $quotes
    ): RedirectResponse {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $quote = $quotes->create($user, $admin, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['custom_quote' => $e->getMessage()]);
        }

        AdminActivity::log('Admin created custom Enterprise quote', [
            'target_user_id' => $user->id,
            'quote_id' => $quote->id,
            'amount' => $quote->amount,
            'credits_granted' => $quote->credits_granted,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'custom-quote-created');
    }

    public function update(
        UpdateCustomQuoteRequest $request,
        User $user,
        CustomQuote $customQuote,
        CustomQuoteService $quotes
    ): RedirectResponse {
        abort_unless((int) $customQuote->user_id === (int) $user->id, 404);

        try {
            $quote = $quotes->update($customQuote, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['custom_quote' => $e->getMessage()]);
        }

        AdminActivity::log('Admin updated custom Enterprise quote', [
            'target_user_id' => $user->id,
            'quote_id' => $quote->id,
            'amount' => $quote->amount,
            'credits_granted' => $quote->credits_granted,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'custom-quote-updated');
    }

    public function destroy(
        User $user,
        CustomQuote $customQuote,
        CustomQuoteService $quotes
    ): RedirectResponse {
        abort_unless((int) $customQuote->user_id === (int) $user->id, 404);

        try {
            $quotes->cancel($customQuote);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['custom_quote' => $e->getMessage()]);
        }

        AdminActivity::log('Admin cancelled custom Enterprise quote', [
            'target_user_id' => $user->id,
            'quote_id' => $customQuote->id,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'custom-quote-cancelled');
    }
}
