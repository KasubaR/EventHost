<?php

namespace App\Http\Requests;

use App\Rules\ZambiaMobileMoneyPhone;
use App\Rules\ZambianPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A contributor's first payment: their details plus the installment amount
 * and payment method. Repeat installments use StoreContributionInstallmentRequest
 * instead — the contributor is already known by then.
 */
class StoreContributionPledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowedMethods = ['mobile_money'];
        if (config('services.lenco.bank_transfer_enabled', true)) {
            $allowedMethods[] = 'bank_transfer';
        }

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', new ZambianPhoneNumber],
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string', Rule::in($allowedMethods)],
            'provider' => ['required_if:payment_method,mobile_money', 'nullable', 'string', Rule::in(['mtn', 'airtel'])],
            'momo_phone' => ['required_if:payment_method,mobile_money', 'nullable', 'string', 'max:20'],
            'bank_name' => ['required_if:payment_method,bank_transfer', 'nullable', 'string', 'max:120'],
        ];

        if ($this->input('payment_method') === 'mobile_money' && is_string($this->input('provider'))) {
            $rules['momo_phone'][] = new ZambiaMobileMoneyPhone($this->input('provider'));
        }

        return $rules;
    }
}
