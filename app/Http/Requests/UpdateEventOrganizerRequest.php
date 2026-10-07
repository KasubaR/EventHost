<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Services\ActingAsService;
use App\Support\ZambianBanks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Wizard step "Organizer Details": who guests can contact about the event, and the bank
 * account ticket revenue is paid out to.
 *
 * Payout fields are the client's alone: while an admin is acting as a client they are
 * neither validated nor saved (see Event privacy promise: never payments), so an admin
 * cannot point a client's revenue at another account.
 */
class UpdateEventOrganizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()?->can('update', $event) === true;
    }

    public function editsPayout(): bool
    {
        return ! app(ActingAsService::class)->isActive($this);
    }

    protected function prepareForValidation(): void
    {
        $number = $this->input('payout_account_number');

        if (is_string($number)) {
            // People type account numbers with spaces or dashes; store the digits.
            $this->merge(['payout_account_number' => preg_replace('/[\s-]+/', '', $number)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'organizer_name' => ['required', 'string', 'max:120'],
            'organizer_phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+\s().-]{7,}$/'],
            'organizer_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'organizer_details_public' => ['required', 'boolean'],
        ];

        if ($this->editsPayout()) {
            $rules += [
                'payout_account_name' => ['required', 'string', 'max:120'],
                'payout_account_number' => ['required', 'string', 'regex:/^[0-9]{6,20}$/'],
                'payout_bank' => ['required', 'string', Rule::in(ZambianBanks::names())],
                'payout_branch' => ['required', 'string', 'max:120'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organizer_phone.regex' => 'Enter a phone number guests can call, for example 0977 123 456.',
            'payout_account_number.regex' => 'The account number should be 6 to 20 digits, with no letters.',
            'payout_bank.in' => 'Choose a bank from the list.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organizer_name' => 'organizer name',
            'organizer_phone' => 'contact number',
            'organizer_email' => 'contact email',
            'payout_account_name' => 'account holder name',
            'payout_account_number' => 'account number',
            'payout_bank' => 'bank',
            'payout_branch' => 'branch',
        ];
    }
}
