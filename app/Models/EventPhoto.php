<?php

namespace App\Models;

use App\Enums\PhotoStatus;
use Database\Factories\EventPhotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPhoto extends Model
{
    /** @use HasFactory<EventPhotoFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_table_id',
        'path',
        'thumbnail_path',
        'uploader_name',
        'status',
        'ip_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'event_table_id' => 'integer',
            'status' => PhotoStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<EventTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(EventTable::class, 'event_table_id');
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->path);
    }

    public function getThumbnailUrlAttribute(): string
    {
        return asset('storage/'.$this->thumbnail_path);
    }

    /**
     * Photos guests/the public gallery may see — never pending or hidden.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', PhotoStatus::Approved);
    }
}
