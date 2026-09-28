<?php

namespace App\Http\Controllers;

use App\Services\EnterpriseQuoteRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnterpriseQuoteRequestController extends Controller
{
    public function store(Request $request, EnterpriseQuoteRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->submit($request->user(), $validated['message'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['enterprise_request' => $e->getMessage()]);
        }

        return redirect()->route('billing.show')->with('status', 'enterprise-request-submitted');
    }
}
