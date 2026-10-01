<?php

namespace App\Models;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client asking our team for hands-on help. It is also the only thing that lets an
 * admin act as that client: access exists while the request is in progress, assigned
 * to that admin and unexpired — see ActingAsService. Plan: plans/admin-create-events.md.
 */
class AdminHelpRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'kind',
        'message',
        'contact_preference',
        'status',
        'assigned_admin_id',
        'claimed_at',
        'access_expires_at',
        'completed_at',
        'decline_note',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => HelpRequestKind::class,
            'status' => HelpRequestStatus::class,
            'claimed_at' => 'datetime',
            'access_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * Requests still holding their owner's single slot (open, or in progress and unexpired).
     *
     * @param  Builder<AdminHelpRequest>  $query
     * @return Builder<AdminHelpRequest>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', HelpRequestStatus::Open->value)
                ->orWhere(function (Builder $q): void {
                    $q->where('status', HelpRequestStatus::InProgress->value)
                        ->where('access_expires_at', '>', now());
                });
        });
    }

    /**
     * Requests that currently let an admin act as the client.
     *
     * @param  Builder<AdminHelpRequest>  $query
     * @return Builder<AdminHelpRequest>
     */
    public function scopeGrantingAccess(Builder $query): Builder
    {
        return $query->where('status', HelpRequestStatus::InProgress->value)
            ->where('access_expires_at', '>', now());
    }

    public function grantsAccess(): bool
    {
        return $this->status === HelpRequestStatus::InProgress
            && $this->access_expires_at !== null
            && $this->access_expires_at->isFuture();
    }

    /**
     * A claimed request whose window has run out is expired. Done lazily, on the paths that
     * read or create requests, rather than by a scheduled job — nothing acts on it in between.
     */
    public static function expireStale(): void
    {
        static::query()
            ->where('status', HelpRequestStatus::InProgress->value)
            ->where('access_expires_at', '<=', now())
            ->update(['status' => HelpRequestStatus::Expired->value]);
    }
}
