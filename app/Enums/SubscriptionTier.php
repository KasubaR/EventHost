<?php

namespace App\Enums;

enum SubscriptionTier: string
{
    case None = 'none';
    case Base = 'base';
    case Pro = 'pro';
    case ProPlus = 'pro_plus';
    case Enterprise = 'enterprise';

    public function rank(): int
    {
        return match ($this) {
            self::None => -1,
            self::Base => 0,
            self::Pro => 1,
            self::ProPlus => 2,
            self::Enterprise => 3,
        };
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }

    public static function normalize(?string $value): self
    {
        return self::tryFromString($value) ?? self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Base => 'Base',
            self::Pro => 'Pro',
            self::ProPlus => 'Pro+',
            self::Enterprise => 'Enterprise',
        };
    }
}
