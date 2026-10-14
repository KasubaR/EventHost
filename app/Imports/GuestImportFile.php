<?php

namespace App\Imports;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;
use ZipArchive;

/**
 * What a guest import file holds, read before any guest is written: its heading row, how many rows sit under it, and
 * whether it can be opened at all. problem() is the one sentence the upload form shows when the file cannot be imported.
 */
final class GuestImportFile
{
    public const UNREADABLE_MESSAGE = "We couldn't read this file. Save it as CSV (comma separated) or as an Excel workbook and upload it again.";

    public const EMPTY_MESSAGE = 'This file has no guests. Put the column headings in the first row and one guest on each row below it.';

    public const TOO_BIG_UNZIPPED_MESSAGE = 'This spreadsheet is too large to open. Save it as CSV (comma separated) and upload that instead.';

    /**
     * @param  list<string>  $headings  The first row as written, trimmed.
     */
    private function __construct(
        public readonly string $readerType,
        public readonly array $headings,
        public readonly int $totalRows,
    ) {}

    /**
     * The reader for a file, from the extension its contents were recognised as (the `mimes` rule checks the same
     * guess). A CSV is often recognised as plain text, hence `txt`.
     */
    public static function readerTypeFor(?string $extension): ?string
    {
        return match (strtolower((string) $extension)) {
            'csv', 'txt' => ExcelType::CSV,
            'xlsx' => ExcelType::XLSX,
            'xls' => ExcelType::XLS,
            default => null,
        };
    }

    /**
     * @throws GuestImportFileException
     */
    public static function inspect(string $path, string $readerType): self
    {
        if ($readerType === ExcelType::XLSX) {
            self::assertUnzippedSizeAllowed($path);
        }

        try {
            $info = IOFactory::createReader($readerType)->listWorksheetInfo($path);
            $sheets = Excel::toArray(new GuestImportHeadingRow, $path, null, $readerType);
        } catch (Throwable) {
            throw new GuestImportFileException(self::UNREADABLE_MESSAGE);
        }

        $headings = array_map(
            fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            array_values($sheets[0][0] ?? [])
        );

        return new self($readerType, $headings, (int) ($info[0]['totalRows'] ?? 0));
    }

    /** Rows under the heading row. Counts blank lines too, so it is an upper bound on guests. */
    public function dataRows(): int
    {
        return max(0, $this->totalRows - 1);
    }

    /**
     * The headings as the import will key them (`Name` and ` name ` both become `name`).
     *
     * @return list<string|int>
     */
    public function headingKeys(): array
    {
        return array_values(HeadingRowFormatter::format($this->headings));
    }

    public function problem(): ?string
    {
        if ($this->totalRows === 0) {
            return self::EMPTY_MESSAGE;
        }

        if (! in_array('name', $this->headingKeys(), true)) {
            $found = array_values(array_filter($this->headings, fn (string $h): bool => $h !== ''));

            if ($found === []) {
                return 'The first row must hold the column headings: name, then any of email, phone, group, table and plus_one.';
            }

            $listed = implode(', ', array_map(fn (string $h): string => '"'.Str::limit($h, 40).'"', array_slice($found, 0, 8)));

            return 'The first row needs a column called "name" for each guest\'s name. This file\'s first row has '.$listed.'. Rename the name column, or download the template.';
        }

        if ($this->dataRows() === 0) {
            return self::EMPTY_MESSAGE;
        }

        $max = self::maxRows();
        if ($this->dataRows() > $max) {
            return 'This file has '.number_format($this->dataRows()).' rows. Import up to '.number_format($max).' guests at a time: split the file and import each part.';
        }

        return null;
    }

    public function runsInBackground(): bool
    {
        return $this->dataRows() > (int) config('events.guest_import.sync_max_rows', 500);
    }

    public static function maxRows(): int
    {
        return (int) config('events.guest_import.max_rows', 20000);
    }

    /**
     * An .xlsx is a zip. A few megabytes can expand to gigabytes of XML, which the reader would try to parse, so the
     * declared sizes of its entries are added up first.
     *
     * @throws GuestImportFileException
     */
    private static function assertUnzippedSizeAllowed(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new GuestImportFileException(self::UNREADABLE_MESSAGE);
        }

        $limit = max(1, (int) config('events.guest_import.max_unzipped_mb', 64)) * 1024 * 1024;
        $total = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += is_array($stat) ? (int) $stat['size'] : 0;

                if ($total > $limit) {
                    throw new GuestImportFileException(self::TOO_BIG_UNZIPPED_MESSAGE);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
