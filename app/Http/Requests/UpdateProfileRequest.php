<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\ZambianPhoneNumber;
use App\Services\ActingAsService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
                // An admin acting as the client must not be able to move the account to
                // an address the client doesn't control.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (app(ActingAsService::class)->isActive($this) && $value !== $this->user()->email) {
                        $fail('The email address cannot be changed while our team is helping with your account.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:20', new ZambianPhoneNumber],
            'company_name' => ['nullable', 'string', 'max:255'],
            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:min_width=100,min_height=100,max_width=2000,max_height=2000',
            ],
        ];
    }
}
