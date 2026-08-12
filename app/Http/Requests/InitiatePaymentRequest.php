<?php

namespace App\Http\Requests;

use App\Rules\ZambiaMobileMoneyPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            'plan_key' => ['required', 'string', Rule::in(array_keys(config('billing.plans', [])))],
            'payment_method' => ['required', 'string', Rule::in($allowedMethods)],
            'provider' => ['required_if:payment_method,mobile_money', 'nullable', 'string', Rule::in(['mtn', 'airtel'])],
            'phone' => ['required_if:payment_method,mobile_money', 'nullable', 'string', 'max:20'],
            'bank_name' => ['required_if:payment_method,bank_transfer', 'nullable', 'string', 'max:120'],
        ];

        if ($this->input('payment_method') === 'mobile_money' && is_string($this->input('provider'))) {
            $rules['phone'][] = new ZambiaMobileMoneyPhone($this->input('provider'));
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.in' => config('services.lenco.bank_transfer_enabled', true)
                ? 'Invalid payment method.'
                : 'Bank transfer is unavailable. Please use mobile money.',
        ];
    }
}
