<?php

namespace App\Models;

use App\Imports\EventGuestsImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guest import running in the background (ImportEventGuests). Counts and the first problems are saved after every
 * batch, so the status page shows real progress and a failed import still says what it added before it stopped.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $user_id
 * @property string $status
 * @property string|null $path
 * @property string $original_name
 * @property string $reader_type
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $skipped_count
 * @property int $capped_count
 * @property int $invalid_count
 * @property list<array{row: int, message: string}>|null $problems
 * @property list<array{row: int, message: string}>|null $skipped_rows
 * @property string|null $failure_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $seen_at
 * @property Carbon|null $updated_at
 */
class GuestImport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * An unfinished import that has not saved progress for this long is treated as dead (a worker that crashed or was
     * never started), so it no longer blocks a new import for the event.
     */
    public const STALE_AFTER_MINUTES = 30;

    public const STALE_MESSAGE = 'This import stopped responding. The counts below are what it added. Import the file again to add the rest: guests already added are skipped when they have an email or phone.';

    public const DISK = 'local';

    protected $fillable = [
        'event_id', 'user_id', 'status', 'path', 'original_name', 'reader_type', 'total_rows', 'processed_rows',
        'created_count', 'skipped_count', 'capped_count', 'invalid_count', 'problems', 'skipped_rows',
        'failure_message', 'started_at', 'finished_at', 'seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'problems' => 'array',
            'skipped_rows' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'seen_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Queued or running, and still showing signs of life.
     *
     * @param  Builder<GuestImport>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING])
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function isStale(): bool
    {
        return ! $this->isFinished()
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** Copies the importer's running totals; the caller saves. */
    public function recordProgress(EventGuestsImport $import): void
    {
        $this->processed_rows = min($this->total_rows, $import->rowsChecked);
        $this->created_count = $import->createdCount;
        $this->skipped_count = $import->skippedCount;
        $this->capped_count = $import->cappedCount;
        $this->invalid_count = $import->invalidCount;
        $this->problems = $import->problems;
        $this->skipped_rows = $import->skippedRows;
    }

    /**
     * The same shape the small import flashes and the API returns, so one partial and one JSON body serve both.
     *
     * @return array{created: int, skipped: int, capped: int, invalid: int, problems: list<array{row: int, message: string}>, skipped_rows: list<array{row: int, message: string}>}
     */
    public function summary(): array
    {
        return [
            'created' => $this->created_count,
            'skipped' => $this->skipped_count,
            'capped' => $this->capped_count,
            'invalid' => $this->invalid_count,
            'problems' => $this->problems ?? [],
            'skipped_rows' => $this->skipped_rows ?? [],
        ];
    }
}
