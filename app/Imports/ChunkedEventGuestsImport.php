<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithChunkReading;

/**
 * The background import (ImportEventGuests). Reading and writing are sized separately: the reader re-parses the file
 * from the top for every slice it hands over, so slices are large (`read_chunk_rows`), while each write batch
 * (`chunk_rows`) is its own short transaction under the event lock.
 */
class ChunkedEventGuestsImport extends EventGuestsImport implements WithChunkReading
{
    public function chunkSize(): int
    {
        return max(1, (int) config('events.guest_import.read_chunk_rows', 5000));
    }

    protected function batchSize(): ?int
    {
        return max(1, (int) config('events.guest_import.chunk_rows', 200));
    }
}
