<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\DB;

/**
 * What Twilio reports after a WhatsApp message left (plans/whatsapp-sharing-gaps.md item 7). A send that Twilio accepted is
 * logged `sent`; whether it reached the phone arrives later on the status callback and is kept in `delivery_status`.
 */
class WhatsAppDeliveryStatusService
{
    /**
     * Apply one status callback. Twilio sends several per message and not always in order, so a status never moves a row
     * backwards (a late "sent" after "delivered" is ignored). An undelivered invitation un-marks the guest, but only when
     * this send is what marked them: a host who has since marked them sent by hand, or shared the link, keeps that.
     */
    public function record(string $messageSid, string $status, ?string $errorCode): void
    {
        $status = strtolower(trim($status));
        if ($messageSid === '' || ! array_key_exists($status, NotificationLog::DELIVERY_ORDER)) {
            return;
        }

        DB::transaction(function () use ($messageSid, $status, $errorCode): void {
            $log = NotificationLog::query()
                ->where('provider_message_id', $messageSid)
                ->where('channel', 'whatsapp')
                ->lockForUpdate()
                ->first();

            if ($log === null) {
                return;
            }

            $current = $log->delivery_status;
            if ($current !== null && (NotificationLog::DELIVERY_ORDER[$current] ?? 0) >= NotificationLog::DELIVERY_ORDER[$status]) {
                return;
            }

            $log->forceFill([
                'delivery_status' => $status,
                'error_code' => filled($errorCode) ? substr((string) $errorCode, 0, 16) : $log->error_code,
            ])->save();

            if (! $log->wasUndelivered() || $log->type !== CommunicationService::WHATSAPP_INVITATION_TYPE || $log->guest_id === null) {
                return;
            }

            $guest = Guest::query()->whereKey($log->guest_id)->lockForUpdate()->first();
            if ($guest === null || ! $guest->invitation_sent || $guest->invitation_sent_at === null || $log->sent_at === null) {
                return;
            }

            if ($guest->invitation_sent_at->getTimestamp() === $log->sent_at->getTimestamp()) {
                $guest->forceFill(['invitation_sent' => false, 'invitation_sent_at' => null])->save();
            }
        });
    }

    /**
     * The guests on this page whose latest automated WhatsApp invitation did not arrive, keyed by guest id.
     *
     * @param  list<int>  $guestIds
     * @return array<int, NotificationLog>
     */
    public function undeliveredInvitations(Event $event, array $guestIds): array
    {
        if ($guestIds === []) {
            return [];
        }

        $latest = NotificationLog::query()
            ->where('event_id', $event->id)
            ->whereIn('guest_id', $guestIds)
            ->where('type', CommunicationService::WHATSAPP_INVITATION_TYPE)
            ->where('status', NotificationLog::STATUS_SENT)
            ->orderBy('id')
            ->get(['id', 'guest_id', 'delivery_status', 'error_code'])
            ->keyBy('guest_id');

        return $latest->filter(fn (NotificationLog $log): bool => $log->wasUndelivered())->all();
    }
}
