<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && $event->isTicketed()
            && auth('admin')->user()?->can('ticketing.payouts.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'note' => $this->note === '' ? null : $this->note,
            // A post that names an amount but no type is a part payment (the form before full/part existed).
            'payment_type' => $this->input('payment_type') ?: ($this->filled('amount') ? 'part' : null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $full = $this->input('payment_type') === 'full';

        return [
            'payment_type' => ['required', 'in:full,part'],
            // A full payment takes whatever the balance is under the lock, so no amount is needed.
            'amount' => [$full ? 'nullable' : 'required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            // Optional receipt, kept on the private disk. Not required for a payout to be recorded.
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }
}
