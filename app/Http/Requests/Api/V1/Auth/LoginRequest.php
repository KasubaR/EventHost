<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stateless twin of App\Http\Requests\Auth\LoginRequest — same validation, same 5-attempt
 * per (email + ip) lockout with 60s decay, same Lockout event. Deliberately drops
 * authenticate(): the web version calls Auth::attempt() (starts a 'web' session) and, on a
 * suspended account, $this->session()->invalidate() — both throw on a stateless `api` route
 * (no StartSession middleware, no session store bound to the request). The controller
 * (Api\V1\Auth\AuthenticatedSessionController) orchestrates the credential check itself via
 * Auth::guard('web')->validate(), which checks credentials with no session/login side effect,
 * and calls this request's ensureIsNotRateLimited()/throttleKey()/hit()-adjacent methods around it.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
