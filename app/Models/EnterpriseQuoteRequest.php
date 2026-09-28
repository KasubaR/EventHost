<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseQuoteRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
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
     * @param  Builder<EnterpriseQuoteRequest>  $query
     * @return Builder<EnterpriseQuoteRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public static function pendingFor(User|int $user): ?self
    {
        $userId = $user instanceof User ? $user->id : $user;

        return static::query()
            ->pending()
            ->where('user_id', $userId)
            ->latest('id')
            ->first();
    }

    public function isPending(): bool
    {
        return $this->dismissed_at === null;
    }
}
