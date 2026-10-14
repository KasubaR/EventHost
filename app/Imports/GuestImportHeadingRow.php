<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The first row of the first sheet, exactly as written, read with the same CSV settings (delimiter and encoding
 * guessing) the import itself uses.
 */
class GuestImportHeadingRow implements WithLimit, WithMultipleSheets
{
    public function limit(): int
    {
        return 1;
    }

    /**
     * @return array<int, self>
     */
    public function sheets(): array
    {
        return [0 => $this];
    }
}
