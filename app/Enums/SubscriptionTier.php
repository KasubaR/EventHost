<?php

namespace App\Enums;

enum SubscriptionTier: string
{
    case Base = 'base';
    case Pro = 'pro';
    case ProPlus = 'pro_plus';

    public function rank(): int
    {
        return match ($this) {
            self::Base => 0,
            self::Pro => 1,
            self::ProPlus => 2,
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
        return self::tryFromString($value) ?? self::Base;
    }

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Base',
            self::Pro => 'Pro',
            self::ProPlus => 'Pro+',
        };
    }
}
