<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\Settings\ProfileController (Slice E).
 * Reuses UpdateProfileRequest and ProfileService::update()/removePhoto()
 * verbatim — both are already guard-agnostic (no session/CSRF assumptions).
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, ProfileService $profileService): JsonResponse
    {
        $user = $profileService->update($request->user(), $request);

        return response()->json(['user' => new UserResource($user)]);
    }

    public function destroyPhoto(Request $request, ProfileService $profileService): JsonResponse
    {
        $user = $profileService->removePhoto($request->user());

        return response()->json(['user' => new UserResource($user)]);
    }
}
