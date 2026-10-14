<?php

namespace App\Jobs;

use App\Imports\ChunkedEventGuestsImport;
use App\Imports\EventGuestsImport;
use App\Imports\GuestImportFile;
use App\Models\Event;
use App\Models\GuestImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Imports a stored guest file too big to run while the host waits (GuestImportFile::runsInBackground()). Each batch of
 * rows commits on its own, so this job is never retried: a second run would add again every guest with no email and
 * no phone that the first run already added.
 */
class ImportEventGuests implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $guestImportId,
        public string $path,
    ) {}

    public function handle(): void
    {
        $record = GuestImport::query()->find($this->guestImportId);

        if ($record === null || $record->isFinished()) {
            $this->deleteFile();

            return;
        }

        $event = Event::query()->find($record->event_id);

        if ($event === null || ! $event->isInvitation()) {
            $this->finish($record, GuestImport::STATUS_FAILED, 'The event was deleted before its guests could be imported.');

            return;
        }

        $record->forceFill(['status' => GuestImport::STATUS_PROCESSING, 'started_at' => now()])->save();

        $import = (new ChunkedEventGuestsImport($event))
            ->afterEachBatch(function (EventGuestsImport $import) use ($record): void {
                $record->recordProgress($import);
                $record->save();
            });

        // The package wraps each slice it reads in one transaction by default, which would hold the event lock across
        // every batch in the slice. The importer opens its own transaction per batch instead.
        $transactionHandler = config('excel.transactions.handler');
        config(['excel.transactions.handler' => 'null']);

        try {
            Excel::import($import, $this->path, GuestImport::DISK, $record->reader_type);
        } catch (Throwable $e) {
            // Class only: a query exception's message carries the row's name, email and phone.
            Log::warning('guest_import.failed', [
                'guest_import_id' => $record->id,
                'event_id' => $record->event_id,
                'exception' => $e::class,
            ]);

            // The batch that failed rolled back, so the saved progress is exactly what was added.
            $record->refresh();
            $this->finish($record, GuestImport::STATUS_FAILED, self::stoppedMessage($record));

            return;
        } finally {
            config(['excel.transactions.handler' => $transactionHandler]);
        }

        $record->recordProgress($import);
        $record->processed_rows = $record->total_rows;

        $this->finish(
            $record,
            $import->rowsRead === 0 ? GuestImport::STATUS_FAILED : GuestImport::STATUS_COMPLETED,
            $import->rowsRead === 0 ? GuestImportFile::EMPTY_MESSAGE : null,
        );
    }

    /**
     * Called by the worker for a timeout, or when the job is picked up a second time. A second pick-up while the first
     * run is still saving progress (the queue's retry_after is shorter than a big import) is not a failure: the first
     * run carries on and finishes the record itself.
     */
    public function failed(Throwable $e): void
    {
        $record = GuestImport::query()->find($this->guestImportId);

        if ($record === null || $record->isFinished()) {
            return;
        }

        $stillRunning = $e instanceof MaxAttemptsExceededException
            && ! $e instanceof TimeoutExceededException
            && $record->status === GuestImport::STATUS_PROCESSING
            && ! $record->isStale();

        if ($stillRunning) {
            return;
        }

        $this->finish($record, GuestImport::STATUS_FAILED, self::stoppedMessage($record));
    }

    public static function stoppedMessage(GuestImport $record): string
    {
        if ($record->processed_rows === 0) {
            return 'The import stopped before it could add anyone. Check the file and import it again.';
        }

        return 'The import stopped part-way: rows up to '.number_format($record->processed_rows + 1).' were imported. '
            .'Import the file again to add the rest. Guests already added are skipped when they have an email or phone; '
            .'remove the imported rows first if many have neither.';
    }

    private function finish(GuestImport $record, string $status, ?string $failure): void
    {
        $record->forceFill([
            'status' => $status,
            'failure_message' => $failure,
            'finished_at' => now(),
            'path' => null,
        ])->save();

        $this->deleteFile();
    }

    private function deleteFile(): void
    {
        Storage::disk(GuestImport::DISK)->delete($this->path);
    }
}
