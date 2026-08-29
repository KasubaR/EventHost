<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_order_id',
        'ticket_order_item_id',
        'event_id',
        'ticket_type_id',
        'public_token',
        'attendee_name',
        'attendee_email',
        'attendee_phone',
        'price_paid',
        'status',
        'issued_at',
    ];

    /**
     * @return BelongsTo<TicketOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(TicketOrder::class, 'ticket_order_id');
    }

    /**
     * @return BelongsTo<TicketOrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(TicketOrderItem::class, 'ticket_order_item_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * Who to credit a check-in to, whichever door it came through: a dashboard
     * scan has a real user, a staff-link scan only has the label its link was
     * snapshotted under. Null means nobody has scanned this ticket yet.
     */
    public function checkedInByLabel(): ?string
    {
        return $this->checkedInBy?->name ?? $this->checked_in_via_label;
    }

    public function qrCacheKey(): string
    {
        return self::qrCacheKeyForToken($this->public_token);
    }

    /**
     * Versioned so raising the QR's error-correction level rolls out to
     * tickets already holding a week-long cached SVG at the old level. Takes a
     * token rather than a model so a reissue can evict the *old* key after the
     * ticket in memory has moved on to its new token.
     */
    public static function qrCacheKeyForToken(string $token): string
    {
        return 'ticket-qr:v2:'.$token;
    }

    /**
     * Retry-on-collision around the unique constraint, which remains the real
     * guard. Shared by issuance (TicketOrderFulfillmentService) and reissue
     * (EventTicketManagementController), so a rotated token is generated
     * exactly the same way as an original one.
     */
    public static function generateUniqueToken(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = Str::random(48);
            if (! self::query()->where('public_token', $token)->exists()) {
                return $token;
            }
        }

        return Str::random(48);
    }

    public function isValid(): bool
    {
        return $this->status === TicketStatus::Valid;
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * Search by attendee name/email/phone for the check-in scanner's manual
     * fallback. Same shape as Guest::scopeSearch(), including LIKE-wildcard
     * escaping so a typed `%` or `_` is a literal, not "match everything".
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = is_string($term) ? trim($term) : '';
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function ($q) use ($like) {
            $q->where('attendee_name', 'like', $like)
                ->orWhere('attendee_email', 'like', $like)
                ->orWhere('attendee_phone', 'like', $like);
        });
    }

    /**
     * Same trust model as Guest::personalRsvpUrl() — the token in the URL is
     * the only guard, no login.
     */
    public function publicUrl(): string
    {
        return route('tickets.show', ['token' => $this->public_token], absolute: true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_order_id' => 'integer',
            'ticket_order_item_id' => 'integer',
            'event_id' => 'integer',
            'ticket_type_id' => 'integer',
            'price_paid' => 'decimal:2',
            'status' => TicketStatus::class,
            'issued_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_in_by' => 'integer',
        ];
    }
}
