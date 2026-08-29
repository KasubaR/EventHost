<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisteredUserRequest;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisteredUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $isOrg = $validated['account_type'] === 'organisation';

        $user = User::create([
            'name' => $validated['name'],
            'account_type' => $validated['account_type'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['phone'] ?? null,
            'company_name' => $isOrg ? $validated['name'] : ($validated['company_name'] ?? null),
        ]);

        event(new Registered($user));

        $user->notify(new WelcomeNotification);

        Auth::login($user);

        return $user instanceof MustVerifyEmail
            ? redirect(route('verification.notice', absolute: false))
            : redirect(route('dashboard', absolute: false));
    }
}
