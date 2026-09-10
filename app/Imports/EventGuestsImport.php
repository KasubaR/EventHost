<?php

namespace App\Imports;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\GuestGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EventGuestsImport implements ToCollection, WithHeadingRow
{
    public int $createdCount = 0;

    public int $skippedCount = 0;

    /**
     * Rows that would have been created but the event's plan-driven guest
     * capacity (Event::guestCapacity()) was already full — reported to the
     * host separately from ordinary duplicate-skips so "why weren't all my
     * guests imported" has a clear answer.
     */
    public int $cappedCount = 0;

    public function __construct(
        protected Event $event,
    ) {}

    public function collection(Collection $rows): void
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

        foreach ($rows as $row) {
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            if ($name === '') {
                continue;
            }

            $emailRaw = isset($row['email']) ? trim((string) $row['email']) : '';
            $email = $emailRaw === '' ? null : strtolower($emailRaw);

            $phoneRaw = isset($row['phone']) ? trim((string) $row['phone']) : '';
            $phone = $phoneRaw === '' ? null : $phoneRaw;

            $groupLabel = isset($row['group']) ? trim((string) $row['group']) : '';
            $guestGroupId = null;

            if ($groupLabel !== '') {
                /** @var GuestGroup $group */
                $group = GuestGroup::query()->firstOrCreate([
                    'event_id' => $this->event->id,
                    'name' => $groupLabel,
                ]);
                $guestGroupId = $group->id;
            }

            $tableLabel = isset($row['table']) ? trim((string) $row['table']) : '';
            $eventTableId = $tableLabel !== ''
                ? $tablesByLabel->get(mb_strtolower($tableLabel))?->id
                : null;

            if ($email !== null && Guest::query()->where('event_id', $this->event->id)->where('email', $email)->exists()) {
                $this->skippedCount++;

                continue;
            }

            if ($phone !== null) {
                $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
                if ($phoneDigits !== '') {
                    if (isset($phonesSeenThisFile[$phoneDigits])) {
                        $this->skippedCount++;

                        continue;
                    }

                    $duplicatePhone = Guest::query()
                        ->where('event_id', $this->event->id)
                        ->whereNotNull('phone')
                        ->get(['id', 'phone'])
                        ->contains(function (Guest $g) use ($phoneDigits): bool {
                            $d = preg_replace('/\D+/', '', (string) $g->phone) ?? '';

                            return $d !== '' && $d === $phoneDigits;
                        });

                    if ($duplicatePhone) {
                        $this->skippedCount++;

                        continue;
                    }

                    $phonesSeenThisFile[$phoneDigits] = true;
                }
            }

            if ($capacity !== null && $currentCount >= $capacity) {
                $this->cappedCount++;

                continue;
            }

            Guest::query()->create([
                'event_id' => $this->event->id,
                'guest_group_id' => $guestGroupId,
                'event_table_id' => $eventTableId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'invitation_token' => Str::random(48),
                'plus_one_allowed' => false,
                'invitation_sent' => false,
                'invitation_sent_at' => null,
            ]);

            $this->createdCount++;
            $currentCount++;
        }
    }
}
