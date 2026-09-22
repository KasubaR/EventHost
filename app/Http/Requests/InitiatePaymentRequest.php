<?php

namespace App\Http\Requests;

use App\Enums\CustomQuoteStatus;
use App\Models\CustomQuote;
use App\Models\Event;
use App\Rules\ZambiaMobileMoneyPhone;
use App\Support\BillingPlan;
use Illuminate\Contracts\Validation\Validator;
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

        $planKeys = array_keys(BillingPlan::all());
        $planKeys[] = 'enterprise';
        $planKeys[] = 'remove_branding';
        $planKeys[] = 'public_registration_quote';

        $rules = [
            'plan_key' => ['required', 'string', Rule::in($planKeys)],
            'quote_id' => ['nullable', 'integer', 'required_if:plan_key,enterprise', 'exists:custom_quotes,id'],
            'event_id' => ['nullable', 'integer', 'required_if:plan_key,remove_branding,public_registration_quote', 'exists:events,id'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('plan_key') === 'enterprise') {
                $this->validateEnterpriseQuote($validator);
            }

            if ($this->input('plan_key') === 'remove_branding') {
                $this->validateBrandingRemovalEvent($validator);
            }

            if ($this->input('plan_key') === 'public_registration_quote') {
                $this->validatePublicRegistrationEvent($validator);
            }
        });
    }

    private function validateEnterpriseQuote(Validator $validator): void
    {
        $quoteId = (int) $this->input('quote_id');
        $quote = CustomQuote::query()->find($quoteId);

        if ($quote === null) {
            return;
        }

        if ((int) $quote->user_id !== (int) $this->user()?->id) {
            $validator->errors()->add('quote_id', 'That custom quote does not belong to your account.');

            return;
        }

        if ($quote->status !== CustomQuoteStatus::Pending) {
            $validator->errors()->add('quote_id', 'That custom quote is no longer available to pay.');
        }
    }

    private function validateBrandingRemovalEvent(Validator $validator): void
    {
        $eventId = (int) $this->input('event_id');
        $event = Event::query()->find($eventId);

        if ($event === null) {
            return;
        }

        if ((int) $event->user_id !== (int) $this->user()?->id) {
            $validator->errors()->add('event_id', 'That event does not belong to your account.');

            return;
        }

        if ($event->branding_removed) {
            $validator->errors()->add('event_id', 'Branding is already removed for that event.');
        }
    }

    private function validatePublicRegistrationEvent(Validator $validator): void
    {
        $eventId = (int) $this->input('event_id');
        $event = Event::query()->find($eventId);

        if ($event === null) {
            return;
        }

        if ((int) $event->user_id !== (int) $this->user()?->id) {
            $validator->errors()->add('event_id', 'That event does not belong to your account.');

            return;
        }

        if (! $event->awaitingPublicRegistrationPayment()) {
            $validator->errors()->add('event_id', 'That event is not awaiting payment.');
        }
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
            'quote_id.required_if' => 'A custom quote is required to pay for Enterprise.',
        ];
    }
}
