<?php

namespace App\Models;

use App\Enums\ContributionStatus;
use Database\Factories\EventContributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contributor's pledge toward an event's fixed contribution amount.
 * amount_paid only ever moves via ContributionPaymentStatusService, under a
 * row lock — never assign it directly. See plans/contributions.md.
 */
class EventContribution extends Model
{
    /** @use HasFactory<EventContributionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'guest_id',
        'reference',
        'contributor_name',
        'contributor_phone',
        'contributor_email',
        'target_amount',
        'amount_paid',
        'currency',
        'status',
        'completed_at',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return HasMany<ContributionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ContributionPayment::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === ContributionStatus::Completed;
    }

    /**
     * What is left to reach target_amount — never negative even if a race
     * somehow let amount_paid overshoot.
     */
    public function remainingAmount(): float
    {
        return max(0.0, round((float) $this->target_amount - (float) $this->amount_paid, 2));
    }

    public static function generateReference(int $eventId): string
    {
        return 'CTB-'.$eventId.'-'.now()->timestamp.'-'.bin2hex(random_bytes(4));
    }

    public static function findByReferenceOrToken(string $reference): ?self
    {
        if (! preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $reference)) {
            return null;
        }

        return static::query()->where('reference', $reference)->first();
    }

    /**
     * Normalized lookup key for "does this phone already have a pledge on
     * this event" — Zambian numbers get typed in several equivalent shapes
     * (0977…, +260977…, 260977…). Same stripping order as
     * ZambianPhoneNumber::localDigits() so the two agree on what "the same
     * number" means.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '260')) {
            return substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'guest_id' => 'integer',
            'target_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'status' => ContributionStatus::class,
            'completed_at' => 'datetime',
        ];
    }
}
