<?php

namespace App\Http\Requests\Auth;

use App\Rules\ZambianPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisteredUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The users.email unique index is case-insensitive (utf8mb4_unicode_ci),
     * so "John@Email.com" already can't collide with a stored "john@email.com"
     * at the database level. Normalizing here instead of validating with a
     * bare 'lowercase' rule means a user who types mixed case gets registered
     * with a clean, consistent email instead of a "must be lowercase" error
     * that gives no indication whether that's really a duplicate.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, array<int, mixed|string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'in:individual,organisation'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:20', new ZambianPhoneNumber],
            'company_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
        ];
    }
}
