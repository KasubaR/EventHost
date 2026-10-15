<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppDeliveryStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

/**
 * Twilio's delivery status callback for outbound WhatsApp (plans/whatsapp-sharing-gaps.md item 7). Signature-verified the
 * same way as TwilioWhatsAppWebhookController. Only the message SID, status and error code are read; the body also holds
 * the guest's number, so it is never logged.
 */
class TwilioWhatsAppStatusController extends Controller
{
    public function __invoke(Request $request, WhatsAppDeliveryStatusService $deliveryStatus): Response
    {
        $authToken = (string) config('services.twilio.auth_token', '');
        if ($authToken === '') {
            abort(403, 'Twilio webhook is not configured.');
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $validator = new RequestValidator($authToken);

        if ($signature === '' || ! $validator->validate($signature, $request->fullUrl(), $request->post())) {
            abort(403, 'Invalid Twilio signature.');
        }

        try {
            $deliveryStatus->record(
                (string) $request->post('MessageSid', ''),
                (string) $request->post('MessageStatus', ''),
                $request->filled('ErrorCode') ? (string) $request->post('ErrorCode') : null,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response('OK', 200);
    }
}
