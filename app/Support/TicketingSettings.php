<?php

namespace App\Support;

use App\Models\PlatformSetting;

class TicketingSettings
{
    public const KEY_COMMISSION_PERCENT = 'ticketing_commission_percent';

    public const KEY_CANCELLATION_FEE_PERCENT = 'ticketing_cancellation_fee_percent';

    public const DEFAULT_COMMISSION_PERCENT = '5.00';

    public const DEFAULT_CANCELLATION_FEE_PERCENT = '0.00';

    public static function commissionPercent(): string
    {
        return self::normalizePercent(
            PlatformSetting::getValue(self::KEY_COMMISSION_PERCENT, self::DEFAULT_COMMISSION_PERCENT),
            self::DEFAULT_COMMISSION_PERCENT
        );
    }

    public static function cancellationFeePercent(): string
    {
        return self::normalizePercent(
            PlatformSetting::getValue(self::KEY_CANCELLATION_FEE_PERCENT, self::DEFAULT_CANCELLATION_FEE_PERCENT),
            self::DEFAULT_CANCELLATION_FEE_PERCENT
        );
    }

    public static function formatZmw(float|int|string $amount): string
    {
        return 'K'.number_format((float) $amount, 2);
    }

    public static function normalizeForStorage(mixed $value): string
    {
        return self::normalizePercent($value, self::DEFAULT_COMMISSION_PERCENT);
    }

    private static function normalizePercent(mixed $value, string $fallback): string
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
