<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one way a group is created or renamed by hand (web and API; plans/guest-groups-gaps.md Phase 3).
 *
 * The request already checked the name, but a rule that runs before the write cannot stop two requests passing it at the same
 * moment. So the name is cleaned and compared again here under the event's row lock (the same lock group sign-ups and deletes
 * take), and if the database's own unique index still refuses it, that becomes the same validation error instead of a 500.
 *
 * "The same name" means the same cleaned, lower-cased text (`GuestGroup::nameKey()`), so "Family  Friends", "family friends"
 * and a name with a zero-width character in it are one group on SQLite and on MySQL alike.
 */
class GuestGroupNamer
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns for the new group
     *
     * @throws ValidationException
     */
    public function create(Event $event, string $name, array $attributes = []): GuestGroup
    {
        try {
            return DB::transaction(function () use ($event, $name, $attributes): GuestGroup {
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $clean = $this->cleaned($name);
                $this->refuseIfTaken($event, $clean, null);
                $this->refuseIfAtCap($event);

                return $event->guestGroups()->create($attributes + ['name' => $clean]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    /**
     * @throws ValidationException
     */
    public function rename(Event $event, GuestGroup $group, string $name): GuestGroup
    {
        try {
            return DB::transaction(function () use ($event, $group, $name): GuestGroup {
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $locked = GuestGroup::query()->whereKey($group->id)->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
                $clean = $this->cleaned($name);
                $this->refuseIfTaken($event, $clean, $locked->id);

                $locked->update(['name' => $clean]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    /**
     * Names the event already uses, as comparison keys. Overridable so a test can stand in for the moment between "checked" and "written".
     *
     * @return list<string>
     */
    protected function takenKeys(Event $event, ?int $ignoreGroupId): array
    {
        return GuestGroup::query()
            ->where('event_id', $event->id)
            ->when($ignoreGroupId !== null, fn ($q) => $q->where('id', '!=', $ignoreGroupId))
            ->pluck('name')
            ->map(fn (string $existing) => GuestGroup::nameKey($existing))
            ->all();
    }

    private function refuseIfTaken(Event $event, string $clean, ?int $ignoreGroupId): void
    {
        if (in_array(GuestGroup::nameKey($clean), $this->takenKeys($event, $ignoreGroupId), true)) {
            throw $this->taken();
        }
    }

    private function refuseIfAtCap(Event $event): void
    {
        if ($event->guestGroups()->count() >= GuestGroup::MAX_PER_EVENT) {
            throw ValidationException::withMessages(['name' => ['An event can have up to '.GuestGroup::MAX_PER_EVENT.' groups. Remove one you no longer need first.']]);
        }
    }

    private function cleaned(string $name): string
    {
        $clean = GuestGroup::cleanName($name);

        if ($clean === null) {
            throw ValidationException::withMessages(['name' => ['The name field is required.']]);
        }

        if (mb_strlen($clean) > GuestGroup::NAME_MAX) {
            throw ValidationException::withMessages(['name' => ['The name field must not be greater than '.GuestGroup::NAME_MAX.' characters.']]);
        }

        return $clean;
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['name' => ['The name has already been taken.']]);
    }
}
