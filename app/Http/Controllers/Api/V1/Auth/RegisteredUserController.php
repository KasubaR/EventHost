<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisteredUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\Auth\RegisteredUserController. Reuses the exact same
 * RegisteredUserRequest (validation-only, no session coupling) and replicates the web
 * controller's business rules verbatim — including the organisation company_name override,
 * which lives in the controller, not the FormRequest. Swaps Auth::login() + redirect for a
 * Sanctum token, since there is no session to log into on a stateless `api` route.
 */
class RegisteredUserController extends Controller
{
    public function store(RegisteredUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $isOrg = $validated['account_type'] === 'organisation';

        $user = User::create([
            'name' => $validated['name'],
            'account_type' => $validated['account_type'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['phone'] ?? null,
            // Matches the web controller exactly: an organisation account's company_name is
            // always the account name, silently overriding anything submitted for that field.
            'company_name' => $isOrg ? $validated['name'] : ($validated['company_name'] ?? null),
        ]);

        event(new Registered($user));

        $user->notify(new WelcomeNotification);

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], 201);
    }

    private function deviceName(Request $request): string
    {
        $name = $request->input('device_name');

        return is_string($name) && $name !== '' ? $name : 'android';
    }
}
