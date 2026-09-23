<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppInboundRsvpService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

/**
 * Public Twilio inbound webhook for WhatsApp (quick-reply RSVP). Signature-verified;
 * always returns 200 after a valid signature so Twilio does not retry soft business failures.
 */
class TwilioWhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppInboundRsvpService $inbound): Response
    {
        $authToken = (string) config('services.twilio.auth_token', '');
        if ($authToken === '') {
            abort(403, 'Twilio webhook is not configured.');
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $validator = new RequestValidator($authToken);
        $url = $request->fullUrl();
        $params = $request->post();

        if ($signature === '' || ! $validator->validate($signature, $url, $params)) {
            abort(403, 'Invalid Twilio signature.');
        }

        try {
            $inbound->handle($params);
        } catch (\Throwable $e) {
            report($e);
        }

        return response('OK', 200);
    }
}
