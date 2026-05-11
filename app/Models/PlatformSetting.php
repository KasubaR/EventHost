<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
    ];

    private const CACHE_PREFIX = 'platform_setting:';

    private const CACHE_TTL_SECONDS = 3600;

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX.$key, self::CACHE_TTL_SECONDS, function () use ($key, $default): mixed {
            $row = static::query()->where('key', $key)->first();

            return $row ? self::castOut($row->type, $row->value) : $default;
        });
    }

    public static function setValue(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = self::castIn($type, $value);

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type]
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    private static function castIn(string $type, mixed $value): ?string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function castOut(string $type, ?string $value): mixed
    {
        return match ($type) {
            'boolean' => $value === '1' || $value === 'true',
            'json' => $value === null || $value === '' ? null : json_decode($value, true),
            default => $value,
        };
    }
}
