<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\Auth\EmailVerificationNotificationController. The actual
 * "tap the link" verification stays the existing web route (verification.verify — signed URL,
 * session-gated, unchanged); the Android app pulls GET /api/v1/me after the user taps that link
 * in a browser/Custom Tab. This endpoint only resends the notification.
 */
class EmailVerificationNotificationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['status' => 'already-verified']);
        }

        $request->user()->sendEmailVerificationNotification();

        // Same string the web flash key already uses — keep them in sync.
        return response()->json(['status' => 'verification-link-sent']);
    }
}
