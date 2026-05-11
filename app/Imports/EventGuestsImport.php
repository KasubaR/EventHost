<?php

namespace App\Imports;

use App\Models\Event;
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

    public function __construct(
        protected Event $event,
    ) {}

    public function collection(Collection $rows): void
    {
        $phonesSeenThisFile = [];

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

            Guest::query()->create([
                'event_id' => $this->event->id,
                'guest_group_id' => $guestGroupId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'invitation_token' => Str::random(48),
                'plus_one_allowed' => false,
                'invitation_sent' => false,
                'invitation_sent_at' => null,
            ]);

            $this->createdCount++;
        }
    }
}
