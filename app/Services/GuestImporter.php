<?php

namespace App\Services;

use App\Imports\EventGuestsImport;
use App\Imports\GuestImportFile;
use App\Jobs\ImportEventGuests;
use App\Models\Event;
use App\Models\GuestImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Exceptions\LaravelExcelException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Throwable;

/**
 * Both guest import endpoints (web and API) start an import here, after StoreGuestImportRequest has read the file's
 * headings and row count. A small file is imported now; a big one is stored and handed to ImportEventGuests.
 */
class GuestImporter
{
    public const BUSY_MESSAGE = 'Guests from another file are still being imported for this event. Wait for that import to finish, then try again.';

    /**
     * @throws ValidationException
     */
    public function start(Event $event, ?User $user, UploadedFile $file, GuestImportFile $inspection): EventGuestsImport|GuestImport
    {
        return $inspection->runsInBackground()
            ? $this->queue($event, $user, $file, $inspection)
            : $this->runNow($event, $file, $inspection);
    }

    /**
     * @throws ValidationException
     */
    private function runNow(Event $event, UploadedFile $file, GuestImportFile $inspection): EventGuestsImport
    {
        if (self::hasActiveImport($event)) {
            throw ValidationException::withMessages(['file' => self::BUSY_MESSAGE]);
        }

        $import = new EventGuestsImport($event);

        try {
            Excel::import($import, $file, null, $inspection->readerType);
        } catch (SpreadsheetException|LaravelExcelException) {
            throw ValidationException::withMessages(['file' => GuestImportFile::UNREADABLE_MESSAGE]);
        }

        // The row count includes blank lines, so a file of only blank rows gets this far having added nobody.
        if ($import->rowsRead === 0) {
            throw ValidationException::withMessages(['file' => GuestImportFile::EMPTY_MESSAGE]);
        }

        return $import;
    }

    /**
     * @throws ValidationException
     */
    private function queue(Event $event, ?User $user, UploadedFile $file, GuestImportFile $inspection): GuestImport
    {
        $extension = match ($inspection->readerType) {
            ExcelType::XLSX => 'xlsx',
            ExcelType::XLS => 'xls',
            default => 'csv',
        };

        $path = $file->storeAs('guest-imports/'.$event->id, Str::uuid()->toString().'.'.$extension, GuestImport::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => GuestImportFile::UNREADABLE_MESSAGE]);
        }

        try {
            $record = DB::transaction(function () use ($event, $user, $file, $inspection, $path): GuestImport {
                // The lock every guest write takes: two uploads at once cannot both find no import running.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                if (self::hasActiveImport($event)) {
                    throw ValidationException::withMessages(['file' => self::BUSY_MESSAGE]);
                }

                return GuestImport::query()->create([
                    'event_id' => $event->id,
                    'user_id' => $user?->id,
                    'status' => GuestImport::STATUS_QUEUED,
                    'path' => $path,
                    'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
                    'reader_type' => $inspection->readerType,
                    'total_rows' => $inspection->dataRows(),
                ]);
            });
        } catch (Throwable $e) {
            Storage::disk(GuestImport::DISK)->delete($path);

            throw $e;
        }

        ImportEventGuests::dispatch($record->id, $path);

        return $record;
    }

    public static function hasActiveImport(Event $event): bool
    {
        return GuestImport::query()->where('event_id', $event->id)->active()->exists();
    }
}
