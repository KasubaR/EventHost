<?php

namespace App\Support;

use App\Models\Event;
use Carbon\Carbon;

/**
 * The facts every Pro wedding layout (InvitationLayoutVariant::proWeddingLayouts()) prints,
 * prepared once so each layout's sections only hold markup. Copy defaults that belong to one
 * look — Ivory's eyebrow, Noir's hero tag and quote — stay in that layout's sections.
 */
final class WeddingInvitationView
{
    public const COUPLE_PHOTO_SLOTS = 3;

    /**
     * @param  array<string, mixed>  $invitation  InvitationCustomizationService::merge() output
     */
    private function __construct(
        private readonly Event $event,
        private readonly array $invitation,
    ) {}

    /**
     * @param  array<string, mixed>  $invitation
     */
    public static function for(Event $event, array $invitation): self
    {
        return new self($event, $invitation);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function names(): array
    {
        return InvitationNames::split((string) $this->event->name);
    }

    public function dateLine(): string
    {
        return $this->event->event_date->format('l, j F Y');
    }

    public function timeLine(): ?string
    {
        if (! $this->event->hasStartTime()) {
            return null;
        }

        return Carbon::parse('2000-01-01 '.substr((string) $this->event->event_time, 0, 8))->format('g:i A');
    }

    public function venueLine(): string
    {
        return trim((string) $this->event->venue);
    }

    /** Empty when it would only repeat the venue. */
    public function locationLine(): string
    {
        $location = trim((string) $this->event->location_name);

        return $location === $this->venueLine() ? '' : $location;
    }

    /**
     * Exactly three browser-loadable URLs. Missing slots repeat the last uploaded portrait, or the
     * cover when none are uploaded, so the grid never renders a hole.
     *
     * @return list<string>
     */
    public function couplePhotos(): array
    {
        $cover = $this->event->cover_image_url;
        $paths = array_values(array_filter(array_map('strval', $this->invitation['media']['couple_photos'] ?? [])));

        if ($paths === []) {
            $paths = [$cover];
        }
        while (count($paths) < self::COUPLE_PHOTO_SLOTS) {
            $paths[] = $paths[count($paths) - 1];
        }

        return array_map(
            static fn (string $path): string => InvitationMediaUrl::resolve($path) ?? $cover,
            array_slice($paths, 0, self::COUPLE_PHOTO_SLOTS)
        );
    }

    /**
     * @return list<array{time: string, title: string, detail: string}>
     */
    public function scheduleRows(): array
    {
        $rows = [];
        foreach ($this->invitation['content']['schedule'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $rows[] = [
                'time' => trim((string) ($row['time'] ?? '')),
                'title' => $title,
                'detail' => trim((string) ($row['detail'] ?? '')),
            ];
        }

        return $rows;
    }

    public function story(): string
    {
        return trim((string) ($this->invitation['content']['story'] ?? ''));
    }

    /**
     * The story split on blank lines into at most one chunk per couple portrait, each paired
     * with its portrait — for layouts that print the story as alternating photo rows. Paragraphs
     * beyond the last portrait join the final chunk so no text is dropped.
     *
     * @return list<array{text: string, photo: string}>
     */
    public function storyChapters(): array
    {
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/', $this->story()) ?: []),
            static fn (string $paragraph): bool => $paragraph !== ''
        ));

        if ($paragraphs === []) {
            return [];
        }

        if (count($paragraphs) > self::COUPLE_PHOTO_SLOTS) {
            $tail = array_splice($paragraphs, self::COUPLE_PHOTO_SLOTS - 1);
            $paragraphs[] = implode("\n\n", $tail);
        }

        $photos = $this->couplePhotos();

        return array_map(
            static fn (string $text, int $i): array => ['text' => $text, 'photo' => $photos[$i]],
            $paragraphs,
            array_keys($paragraphs)
        );
    }
}
