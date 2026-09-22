<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;

/**
 * JSON sibling of App\Http\Controllers\Settings\NotificationController
 * (Slice E). Reuses UpdateNotificationPreferencesRequest and
 * ProfileService::updateNotificationPreferences() verbatim.
 */
class NotificationController extends Controller
{
    public function update(UpdateNotificationPreferencesRequest $request, ProfileService $profileService): JsonResponse
    {
        $user = $profileService->updateNotificationPreferences($request->user(), $request->preferences());

        return response()->json(['user' => new UserResource($user)]);
    }
}
