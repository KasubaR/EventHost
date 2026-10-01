<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioReport extends Model
{
    public const OPEN = 'open';

    public const REMOVED = 'removed';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'event_id',
        'event_name',
        'audio_path',
        'reporter_name',
        'reporter_email',
        'rights_holder',
        'details',
        'status',
        'handled_by_admin_id',
        'handled_at',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
