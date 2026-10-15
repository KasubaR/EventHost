<?php

namespace App\Imports;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Support\GuestPhone;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as EmailValidator;
use Maatwebsite\Excel\Concerns\RemembersChunkOffset;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Reads the first sheet only: GuestImportFile checks that sheet's headings and row count, so a second sheet of notes
 * is never imported as guests. Run as-is for a small file (one transaction) or through ChunkedEventGuestsImport, which
 * calls collection() once per chunk; the counters and the "seen earlier in this file" maps live on the object, so they
 * span every chunk.
 */
class EventGuestsImport implements ToCollection, WithHeadingRow, WithMultipleSheets
{
    use RemembersChunkOffset;

    /** How many refused and skipped rows are kept for the report; the counts still cover every one. */
    public const PROBLEMS_SHOWN = 50;

    public int $createdCount = 0;

    /** Rows left out because the guest is already on the list or earlier in the file. */
    public int $skippedCount = 0;

    /**
     * Rows that would have been created but the event's plan-driven guest
     * capacity (Event::guestCapacity()) was already full — reported to the
     * host separately from ordinary duplicate-skips so "why weren't all my
     * guests imported" has a clear answer.
     */
    public int $cappedCount = 0;

    /** Rows refused because a value would not pass the add-guest form (no name, name too long, bad email or phone). */
    public int $invalidCount = 0;

    /** Rows with anything in them. A fully blank line is not a guest and is not counted. */
    public int $rowsRead = 0;

    /** Every row looked at, blank ones included: the background import's progress. */
    public int $rowsChecked = 0;

    /** @var list<array{row: int, message: string}> The first PROBLEMS_SHOWN invalid rows, by spreadsheet row number. */
    public array $problems = [];

    /** @var list<array{row: int, message: string}> The first PROBLEMS_SHOWN skipped rows, by spreadsheet row number. */
    public array $skippedRows = [];

    /** @var array<string, int> Email imported from this file => its row number. */
    private array $emailRows = [];

    /** @var array<string, int> GuestPhone::key() imported from this file => its row number. */
    private array $phoneRows = [];

    /** @var array<string, int> Name + email + phone imported from this file => its row number. */
    private array $exactRows = [];

    /** @var array<string, int> Group name => id, for the batch being written. */
    private array $groupIds = [];

    private ?EmailValidator $emailValidator = null;

    /** @var (Closure(self): void)|null */
    private ?Closure $afterBatch = null;

    public function __construct(
        protected Event $event,
    ) {}

    /**
     * @return array<int, self>
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    /**
     * Runs inside each batch's transaction, after its rows are written, so progress saved there commits with them.
     *
     * @param  Closure(self): void  $callback
     */
    public function afterEachBatch(Closure $callback): static
    {
        $this->afterBatch = $callback;

        return $this;
    }

    /** Rows written per transaction; null writes everything the reader hands over in one. */
    protected function batchSize(): ?int
    {
        return null;
    }

    /**
     * Each batch is one transaction under the event's row lock (the lock GuestCreator and GroupRsvpService take too):
     * a database error part-way leaves the batch as it was rather than half-imported, and a guest added by hand at
     * the same moment cannot slip a duplicate email or phone between this batch's check and its insert.
     */
    public function collection(Collection $rows): void
    {
        $rows = $rows->values();
        $batches = $this->batchSize() === null ? [$rows] : $rows->chunk($this->batchSize());

        foreach ($batches as $batch) {
            DB::transaction(function () use ($batch): void {
                Event::query()->whereKey($this->event->id)->lockForUpdate()->firstOrFail();

                $this->importRows($batch);

                if ($this->afterBatch !== null) {
                    ($this->afterBatch)($this);
                }
            });
        }
    }

    /**
     * @param  Collection<int, Collection<string|int, mixed>>  $rows  Keyed by position in what the reader handed over.
     */
    private function importRows(Collection $rows): void
    {
        $capacity = $this->event->guestCapacity();
        $currentCount = $capacity !== null ? $this->event->guests()->count() : 0;

        // Unlike Group, a table label is matched against tables the host already
        // created (each backs a real, physically printed photo-wall QR sign) —
        // never auto-created from a typo in the spreadsheet. Case-insensitive,
        // built once rather than once per row.
        $tablesByLabel = EventTable::query()
            ->where('event_id', $this->event->id)
            ->get(['id', 'label'])
            ->keyBy(fn (EventTable $t) => mb_strtolower(trim($t->label)));

        // Read once per batch, inside the lock, instead of one query per row.
        $listedEmails = $this->listedEmails($rows);
        $listedPhoneKeys = $this->listedPhoneKeys();

        // A group deleted by hand between batches must not be reused by id.
        $this->groupIds = [];
        $pending = [];

        // Row 1 is the heading row; a chunk read starts wherever the reader says it does.
        $firstRowNumber = $this->getChunkOffset() ?? 2;

        foreach ($rows as $index => $row) {
            $rowNumber = $firstRowNumber + $index;
            $this->rowsChecked++;

            if ($this->isBlank($row)) {
                continue;
            }

            $this->rowsRead++;

            $name = Guest::cleanName(isset($row['name']) ? (string) $row['name'] : null);
            if ($name === null) {
                $this->refuse($rowNumber, 'the name is empty.');

                continue;
            }

            if (mb_strlen($name) > Guest::NAME_MAX) {
                $this->refuse($rowNumber, 'the name is longer than '.Guest::NAME_MAX.' characters.');

                continue;
            }

            $emailRaw = $this->cell($row, 'email');
            $email = $emailRaw === '' ? null : strtolower($emailRaw);

            if ($email !== null && ! $this->isValidEmail($email)) {
                $this->refuse($rowNumber, '"'.Str::limit($emailRaw, 60).'" is not a valid email address.');

                continue;
            }

            $phoneRaw = $this->cell($row, 'phone');
            $phone = $phoneRaw === '' ? null : $phoneRaw;

            if ($phone !== null && ($problem = GuestPhone::problem($phone)) !== null) {
                $this->refuse($rowNumber, 'phone "'.Str::limit($phone, 40).'": '.lcfirst($problem));

                continue;
            }

            $phoneKey = $phone !== null ? GuestPhone::key($phone) : null;

            if ($email !== null && isset($this->emailRows[$email])) {
                $this->skip($rowNumber, 'row '.$this->emailRows[$email].' has the same email.');

                continue;
            }

            if ($email !== null && isset($listedEmails[$email])) {
                $this->skip($rowNumber, 'a guest with this email is already on the list.');

                continue;
            }

            if ($phoneKey !== null && isset($this->phoneRows[$phoneKey])) {
                $this->skip($rowNumber, 'row '.$this->phoneRows[$phoneKey].' has the same phone number.');

                continue;
            }

            if ($phoneKey !== null && isset($listedPhoneKeys[$phoneKey])) {
                $this->skip($rowNumber, 'a guest with this phone number is already on the list.');

                continue;
            }

            // Only reachable for a row with no email and no phone: the same name typed twice with different
            // contact details is two people, the same row pasted twice is one.
            $exactKey = mb_strtolower($name)."\u{0}".($email ?? '')."\u{0}".($phoneKey ?? $phone ?? '');
            if (isset($this->exactRows[$exactKey])) {
                $this->skip($rowNumber, 'it repeats row '.$this->exactRows[$exactKey].'.');

                continue;
            }

            if ($capacity !== null && $currentCount >= $capacity) {
                $this->cappedCount++;

                continue;
            }

            $tableLabel = $this->cell($row, 'table');

            $pending[] = [
                'row' => $rowNumber,
                'email' => $email,
                'phone_key' => $phoneKey,
                'exact_key' => $exactKey,
                'group' => mb_substr($this->cell($row, 'group'), 0, 191),
                'attributes' => [
                    'event_id' => $this->event->id,
                    'event_table_id' => $tableLabel !== '' ? $tablesByLabel->get(mb_strtolower($tableLabel))?->id : null,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'invitation_token' => Str::random(48),
                    'plus_one_allowed' => $this->wantsPlusOne($row['plus_one'] ?? null),
                    'invitation_sent' => false,
                    'invitation_sent_at' => null,
                ],
            ];
            $currentCount++;

            if ($email !== null) {
                $this->emailRows[$email] = $rowNumber;
            }

            if ($phoneKey !== null) {
                $this->phoneRows[$phoneKey] = $rowNumber;
            }

            $this->exactRows[$exactKey] = $rowNumber;
        }

        $this->insertGuests($pending);
    }

    /**
     * The batch's guests, and the groups their rows name, in one savepoint and a few multi-row inserts. If a unique
     * email the checks above missed (another casing already stored, say) refuses the insert, the savepoint is rolled
     * back and the rows go in one at a time, so only the colliding row is skipped.
     *
     * @param  list<array{row: int, email: ?string, phone_key: ?string, exact_key: string, group: string, attributes: array<string, mixed>}>  $pending
     */
    private function insertGuests(array $pending): void
    {
        if ($pending === []) {
            return;
        }

        $groupIds = $this->groupIds;

        try {
            DB::transaction(function () use ($pending): void {
                $now = now();
                $records = array_map(fn (array $guest): array => $guest['attributes'] + [
                    'guest_group_id' => $this->groupId($guest['group']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $pending);

                foreach (array_chunk($records, 100) as $chunk) {
                    Guest::query()->insert($chunk);
                }
            });

            $this->createdCount += count($pending);

            return;
        } catch (UniqueConstraintViolationException) {
            $this->groupIds = $groupIds;
        }

        foreach ($pending as $guest) {
            $groupIds = $this->groupIds;

            try {
                DB::transaction(fn () => Guest::query()->create($guest['attributes'] + ['guest_group_id' => $this->groupId($guest['group'])]));
                $this->createdCount++;
            } catch (UniqueConstraintViolationException) {
                $this->groupIds = $groupIds;
                $this->forgetRow($guest);
                $this->skip($guest['row'], 'a guest with this email is already on the list.');
            }
        }
    }

    /** Created only when a row that names the group is written, so a refused row cannot leave an empty group behind. */
    private function groupId(string $label): ?int
    {
        if ($label === '') {
            return null;
        }

        // The same cleaning and comparison as the host's own form, so "Family ", "family" and "Fam\u{200B}ily" in one file are one group.
        $clean = GuestGroup::cleanName($label);
        if ($clean === null) {
            return null;
        }

        $key = GuestGroup::nameKey($clean);

        return $this->groupIds[$key] ??= $this->findOrCreateGroup($clean, $key);
    }

    private function findOrCreateGroup(string $clean, string $key): int
    {
        $existing = GuestGroup::query()->where('event_id', $this->event->id)->get(['id', 'name'])
            ->first(fn (GuestGroup $group) => GuestGroup::nameKey($group->name) === $key);

        // createOrFirst: a second writer creating the same name between the look-up and the insert returns theirs, and is never mistaken for a duplicate guest.
        return ($existing ?? GuestGroup::query()->createOrFirst(['event_id' => $this->event->id, 'name' => $clean]))->id;
    }

    /**
     * @param  array{row: int, email: ?string, phone_key: ?string, exact_key: string}  $guest
     */
    private function forgetRow(array $guest): void
    {
        foreach (['emailRows' => $guest['email'], 'phoneRows' => $guest['phone_key'], 'exactRows' => $guest['exact_key']] as $map => $key) {
            if ($key !== null && ($this->{$map}[$key] ?? null) === $guest['row']) {
                unset($this->{$map}[$key]);
            }
        }
    }

    /**
     * Emails in this batch that are already on the event, lower-cased.
     *
     * @param  Collection<int, Collection<string|int, mixed>>  $rows
     * @return array<string, true>
     */
    private function listedEmails(Collection $rows): array
    {
        $emails = $rows
            ->map(fn (Collection $row): string => strtolower($this->cell($row, 'email')))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values();

        $listed = [];

        foreach ($emails->chunk(500) as $chunk) {
            Guest::query()
                ->where('event_id', $this->event->id)
                ->whereIn(DB::raw('LOWER(email)'), $chunk->all())
                ->pluck('email')
                ->each(function (?string $email) use (&$listed): void {
                    if ($email !== null) {
                        $listed[strtolower($email)] = true;
                    }
                });
        }

        return $listed;
    }

    /**
     * Every phone already on the event, by GuestPhone::key() (the comparison Guest::phoneAlreadyUsed() makes).
     *
     * @return array<string, true>
     */
    private function listedPhoneKeys(): array
    {
        $keys = [];

        Guest::query()
            ->where('event_id', $this->event->id)
            ->whereNotNull('phone')
            ->pluck('phone')
            ->each(function (string $phone) use (&$keys): void {
                $key = GuestPhone::key($phone);
                if ($key !== null) {
                    $keys[$key] = true;
                }
            });

        return $keys;
    }

    /** The add-guest form's email rule. One validator is reused for every row: building one per row was most of a big file's check time. */
    private function isValidEmail(string $email): bool
    {
        $this->emailValidator ??= Validator::make([], ['email' => ['email:rfc', 'max:'.Guest::EMAIL_MAX]]);

        return $this->emailValidator->setData(['email' => $email])->passes();
    }

    /**
     * @param  Collection<string|int, mixed>  $row
     */
    private function cell(Collection $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  Collection<string|int, mixed>  $row
     */
    private function isBlank(Collection $row): bool
    {
        return $row->every(fn (mixed $value): bool => $value === null || (is_scalar($value) && trim((string) $value) === ''));
    }

    private function refuse(int $rowNumber, string $message): void
    {
        $this->invalidCount++;

        if (count($this->problems) < self::PROBLEMS_SHOWN) {
            $this->problems[] = ['row' => $rowNumber, 'message' => $message];
        }
    }

    private function skip(int $rowNumber, string $message): void
    {
        $this->skippedCount++;

        if (count($this->skippedRows) < self::PROBLEMS_SHOWN) {
            $this->skippedRows[] = ['row' => $rowNumber, 'message' => $message];
        }
    }

    /**
     * Optional `plus_one` column: yes / y / true / 1 allow it, anything else (or no column) does not.
     * Stored even while the event toggle is off, so switching plus-ones on later needs no re-import.
     */
    private function wantsPlusOne(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', 'y', 'true', '1'], true);
    }
}
