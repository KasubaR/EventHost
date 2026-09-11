<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * JSON sibling of App\Http\Controllers\Auth\PasswordResetLinkController. The web version's
 * Password::sendResetLink() call is already stateless — reused verbatim, only the
 * redirect/flash response is swapped for JSON.
 */
class PasswordResetLinkController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status == Password::RESET_LINK_SENT) {
            return response()->json(['status' => __($status)]);
        }

        throw ValidationException::withMessages([
            'email' => __($status),
        ]);
    }
}
