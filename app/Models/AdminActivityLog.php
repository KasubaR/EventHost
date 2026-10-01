<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One thing an admin did while acting as a client. Append-only. Plan: plans/admin-create-events.md (Step 3).
 */
class AdminActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'admin_activity_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admin_id',
        'user_id',
        'event_id',
        'help_request_id',
        'action',
        'properties',
        'ip',
    ];

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'session_started' => 'Started acting as the client',
        'session_ended' => 'Stopped acting as the client',
        'event_created' => 'Created an event',
        'event_updated' => 'Updated an event',
        'event_published' => 'Published an event',
        'event_paused' => 'Paused an event',
        'event_cancelled' => 'Cancelled an event',
        'event_deleted' => 'Deleted an event',
        'event_restored' => 'Restored an event',
        'credits_spent' => 'Spent event credits',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? Str::of($this->action)->replace(['.', '_', '-'], ' ')->ucfirst()->toString();
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }
}
