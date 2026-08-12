<?php

namespace App\Models;

use Database\Factories\EventTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTable extends Model
{
    /** @use HasFactory<EventTableFactory> */
    use HasFactory;

    /** Visually-unambiguous alphabet (no 0/O/1/I/l) since codes are read off printed cards. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'label',
        'code',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (EventTable $table): void {
            if (! is_string($table->code) || $table->code === '') {
                $table->code = self::generateUniqueCode();
            }
        });
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<EventPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(EventPhoto::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'sort_order' => 'integer',
            'photos_count' => 'integer',
        ];
    }

    public function publicUploadUrl(): string
    {
        $this->loadMissing('event');

        return route('table.upload.show', [
            'slug' => $this->event->slug,
            'code' => $this->code,
        ], absolute: true);
    }

    public static function generateUniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }
}
