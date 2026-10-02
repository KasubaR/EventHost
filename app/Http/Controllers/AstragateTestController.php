<?php

namespace App\Http\Controllers;

use App\Services\AstragateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use RuntimeException;

/**
 * Sandbox-only test page for Astragate, served from a secret URL
 * (`services.astragate.test_path`). It creates no `payments` rows and grants no
 * credits, so it can sit on the live site without touching real billing.
 * The route is not registered at all unless the path is configured.
 */
class AstragateTestController extends Controller
{
    public const MAX_AMOUNT = 20;

    public const CACHE_PREFIX = 'astragate.test.';

    public function show(Request $request): View
    {
        $reference = (string) $request->query('ref', '');
        $record = $reference !== '' ? Cache::get(self::CACHE_PREFIX.$reference) : null;

        return view('astragate-test', [
            'path' => $this->path(),
            'configured' => AstragateService::configured(),
            'maxAmount' => self::MAX_AMOUNT,
            'reference' => $reference,
            'record' => is_array($record) ? $record : null,
        ]);
    }

    public function initiate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.self::MAX_AMOUNT],
        ]);

        $reference = 'TEST-'.now()->timestamp.'-'.bin2hex(random_bytes(3));

        try {
            $result = app(AstragateService::class)->initiateCollection([
                'reference' => $reference,
                'amount' => (float) $data['amount'],
                'description' => 'Event Host sandbox test',
            ], $data['phone']);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        Cache::put(self::CACHE_PREFIX.$reference, [
            'amount' => (float) $data['amount'],
            'initiated' => $result['rawResponse'],
            'status' => $result['status'],
            'callback' => null,
        ], now()->addDay());

        return redirect()->route('astragate.test', ['ref' => $reference]);
    }

    /** Asks Astragate for the live status instead of waiting on the callback. */
    public function check(string $reference): RedirectResponse
    {
        $key = self::CACHE_PREFIX.$reference;
        $record = Cache::get($key);
        abort_unless(is_array($record), 404);

        try {
            $verification = app(AstragateService::class)->verifyByReference($reference);
            $record['status'] = $verification['status'];
            $record['checked'] = $verification['rawResponse'];
            Cache::put($key, $record, now()->addDay());
        } catch (RuntimeException $e) {
            return redirect()->route('astragate.test', ['ref' => $reference])->with('error', $e->getMessage());
        }

        return redirect()->route('astragate.test', ['ref' => $reference]);
    }

    private function path(): string
    {
        return trim((string) config('services.astragate.test_path'), '/');
    }
}
