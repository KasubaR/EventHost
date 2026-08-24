<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventSlugRedirect;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventSlugService
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 60;

    /**
     * Whether $slug is free for $eventId (null = creating). Soft-deleted
     * events keep their slug reserved. Own historical redirects are reclaimable.
     */
    public function isAvailable(string $slug, ?int $eventId = null): bool
    {
        $takenByEvent = Event::withTrashed()
            ->where('slug', $slug)
            ->when($eventId !== null, fn ($q) => $q->where('id', '!=', $eventId))
            ->exists();

        if ($takenByEvent) {
            return false;
        }

        $takenByRedirect = EventSlugRedirect::query()
            ->where('slug', $slug)
            ->when($eventId !== null, fn ($q) => $q->where('event_id', '!=', $eventId))
            ->exists();

        return ! $takenByRedirect;
    }

    /**
     * Apply a host-chosen slug. Empty/null leaves Sluggable to generate on create,
     * or keeps the current slug on update.
     *
     * @throws ValidationException
     */
    public function apply(?string $slug, Event $event): void
    {
        $slug = is_string($slug) ? trim($slug) : '';

        if ($slug === '') {
            return;
        }

        $slug = strtolower($slug);

        if ($event->exists && $event->slug === $slug) {
            return;
        }

        if (! $this->isAvailable($slug, $event->exists ? $event->id : null)) {
            throw ValidationException::withMessages([
                'slug' => 'That custom URL is already taken.',
            ]);
        }

        DB::transaction(function () use ($slug, $event): void {
            if ($event->exists) {
                // Reclaiming one of this event's old slugs — drop the redirect row.
                EventSlugRedirect::query()
                    ->where('event_id', $event->id)
                    ->where('slug', $slug)
                    ->delete();

                if (filled($event->slug) && $event->slug !== $slug) {
                    EventSlugRedirect::query()->firstOrCreate(
                        ['slug' => $event->slug],
                        [
                            'event_id' => $event->id,
                            'created_at' => now(),
                        ]
                    );
                }
            }

            $event->slug = $slug;
        });
    }

    /**
     * When no custom slug is given, Event::sluggable() auto-generates one from
     * the name via cviebrock/eloquent-sluggable. That package only checks
     * uniqueness against the events table itself (now including trashed rows —
     * see Event::sluggable()'s 'includeTrashed') — it has no idea
     * event_slug_redirects exists. Without this, a freshly created event's
     * name-derived slug could silently match a value already reserved there,
     * hijacking a *different* event's old, still-shared URL with no error of
     * any kind (a separate table's unique index doesn't fire).
     *
     * Call this once, right after the event's first save. It is a no-op unless
     * the slug Sluggable (or a caller) just wrote collides with someone else's
     * redirect row.
     */
    public function resolveAutoSlugCollision(Event $event): void
    {
        $slug = $event->slug;

        if (! is_string($slug) || $slug === '' || ! $event->exists) {
            return;
        }

        $collides = EventSlugRedirect::query()
            ->where('slug', $slug)
            ->where('event_id', '!=', $event->id)
            ->exists();

        if (! $collides) {
            return;
        }

        $suffix = 2;
        do {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        } while (! $this->isAvailable($candidate, $event->id));

        $event->slug = $candidate;
        $event->save();
    }

    /**
     * isAvailable()'s check and the eventual save() aren't covered by one lock,
     * so two concurrent requests can both pass the check before either writes —
     * the events.slug unique index still stops a real duplicate row, but as a
     * raw QueryException, not the friendly message apply() normally throws.
     * Callers should catch \Throwable around save(), pass it here, and convert
     * a true positive into the same ValidationException apply() would have
     * thrown had it lost the race.
     */
    public static function isSlugUniqueViolation(\Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        // SQLSTATE 23000 is "integrity constraint violation" on every driver
        // this app runs on (MySQL, SQLite); the message names the offending
        // column/index, which is how we tell a slug collision apart from any
        // other unique constraint failing in the same request.
        return $e->getCode() === '23000' && str_contains(strtolower($e->getMessage()), 'slug');
    }
}
