<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Receives Astragate callbacks at a secret URL
 * (`POST /webhooks/astragate/{secret}`). Astragate does not sign callbacks, so a
 * wrong or unconfigured secret 404s — the route looks like it does not exist.
 *
 * Sandbox only for now: it records `TEST-` collections from the secret test page
 * and acknowledges everything else. It never touches the `payments` table.
 */
class AstragateWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        $expected = (string) config('services.astragate.webhook_secret');

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $correlatorId = (string) $request->input('correlatorId', '');
        $key = AstragateTestController::CACHE_PREFIX.$correlatorId;
        $record = str_starts_with($correlatorId, 'TEST-') ? Cache::get($key) : null;

        if (is_array($record)) {
            $record['callback'] = $request->all();
            $record['status'] = \App\Services\AstragateService::mapStatusCode(
                is_scalar($request->input('statusCode')) ? $request->input('statusCode') : null
            );
            Cache::put($key, $record, now()->addDay());
        }

        return response()->json(['success' => true, 'message' => 'acknowledged']);
    }
}
