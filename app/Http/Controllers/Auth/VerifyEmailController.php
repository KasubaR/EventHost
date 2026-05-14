<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\SafeIntendedUrl;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return SafeIntendedUrl::redirect($request, route('dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            $request->user()->forceFill(['status' => 'active'])->save();

            event(new Verified($request->user()));
        }

        return SafeIntendedUrl::redirect($request, route('dashboard', absolute: false).'?verified=1');
    }
}
