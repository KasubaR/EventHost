<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
