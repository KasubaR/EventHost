<?php

namespace App\Imports;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Support\GuestPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EventGuestsImport implements ToCollection, WithHeadingRow
{
    /** How many row problems are kept for the report; the count still covers every one. */
    public const PROBLEMS_SHOWN = 10;

    public int $createdCount = 0;

    public int $skippedCount = 0;

    /**
     * Rows that would have been created but the event's plan-driven guest
     * capacity (Event::guestCapacity()) was already full — reported to the
     * host separately from ordinary duplicate-skips so "why weren't all my
     * guests imported" has a clear answer.
     */
    public int $cappedCount = 0;

    /** Rows refused because a value would not pass the add-guest form (name too long, bad email or phone). */
    public int $invalidCount = 0;

    /** @var list<array{row: int, message: string}> The first PROBLEMS_SHOWN invalid rows, by spreadsheet row number. */
    public array $problems = [];

    public function __construct(
        protected Event $event,
    ) {}

    /**
     * One transaction under the event's row lock (the lock GuestCreator and GroupRsvpService take too): a database
     * error part-way leaves the list as it was rather than half-imported, and a guest added by hand at the same
     * moment cannot slip a duplicate email or phone between this file's check and its insert.
     */
    public function collection(Collection $rows): void
    {
        DB::transaction(function () use ($rows): void {
            Event::query()->whereKey($this->event->id)->lockForUpdate()->firstOrFail();

            $this->importRows($rows);
        });
    }

    private function importRows(Collection $rows): void
    {
        $capacity = $this->event->guestCapacity();
        $currentCount = $capacity !== null ? $this->event->guests()->count() : 0;

        $phonesSeenThisFile = [];

        // Unlike Group, a table label is matched against tables the host already
        // created (each backs a real, physically printed photo-wall QR sign) —
        // never auto-created from a typo in the spreadsheet. Case-insensitive,
        // built once rather than once per row.
        $tablesByLabel = EventTable::query()
            ->where('event_id', $this->event->id)
            ->get(['id', 'label'])
            ->keyBy(fn (EventTable $t) => mb_strtolower(trim($t->label)));

        foreach ($rows->values() as $index => $row) {
            // Row 1 is the heading row.
            $rowNumber = $index + 2;

            $name = Guest::cleanName(isset($row['name']) ? (string) $row['name'] : null);
            if ($name === null) {
                continue;
            }

            if (mb_strlen($name) > Guest::NAME_MAX) {
                $this->refuse($rowNumber, 'the name is longer than '.Guest::NAME_MAX.' characters.');

                continue;
            }

            $emailRaw = isset($row['email']) ? trim((string) $row['email']) : '';
            $email = $emailRaw === '' ? null : strtolower($emailRaw);

            if ($email !== null && Validator::make(['email' => $email], ['email' => ['email:rfc', 'max:'.Guest::EMAIL_MAX]])->fails()) {
                $this->refuse($rowNumber, '"'.Str::limit($emailRaw, 60).'" is not a valid email address.');

                continue;
            }

            $phoneRaw = isset($row['phone']) ? trim((string) $row['phone']) : '';
            $phone = $phoneRaw === '' ? null : $phoneRaw;

            if ($phone !== null && ($problem = GuestPhone::problem($phone)) !== null) {
                $this->refuse($rowNumber, 'phone "'.Str::limit($phone, 40).'": '.lcfirst($problem));

                continue;
            }

            if ($email !== null && Guest::query()->where('event_id', $this->event->id)->where('email', $email)->exists()) {
                $this->skippedCount++;

                continue;
            }

            if ($phone !== null) {
                $phoneKey = GuestPhone::key($phone);
                if ($phoneKey !== null) {
                    if (isset($phonesSeenThisFile[$phoneKey])) {
                        $this->skippedCount++;

                        continue;
                    }

                    if (Guest::phoneAlreadyUsed($this->event, $phone)) {
                        $this->skippedCount++;

                        continue;
                    }

                    $phonesSeenThisFile[$phoneKey] = true;
                }
            }

            if ($capacity !== null && $currentCount >= $capacity) {
                $this->cappedCount++;

                continue;
            }

            // Created only for a row that is actually imported, so a refused row cannot leave an empty group behind.
            $groupLabel = isset($row['group']) ? trim((string) $row['group']) : '';
            $guestGroupId = null;

            if ($groupLabel !== '') {
                /** @var GuestGroup $group */
                $group = GuestGroup::query()->firstOrCreate([
                    'event_id' => $this->event->id,
                    'name' => mb_substr($groupLabel, 0, 191),
                ]);
                $guestGroupId = $group->id;
            }

            $tableLabel = isset($row['table']) ? trim((string) $row['table']) : '';
            $eventTableId = $tableLabel !== ''
                ? $tablesByLabel->get(mb_strtolower($tableLabel))?->id
                : null;

            Guest::query()->create([
                'event_id' => $this->event->id,
                'guest_group_id' => $guestGroupId,
                'event_table_id' => $eventTableId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'invitation_token' => Str::random(48),
                'plus_one_allowed' => $this->wantsPlusOne($row['plus_one'] ?? null),
                'invitation_sent' => false,
                'invitation_sent_at' => null,
            ]);

            $this->createdCount++;
            $currentCount++;
        }
    }

    private function refuse(int $rowNumber, string $message): void
    {
        $this->invalidCount++;

        if (count($this->problems) < self::PROBLEMS_SHOWN) {
            $this->problems[] = ['row' => $rowNumber, 'message' => $message];
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
