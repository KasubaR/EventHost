<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/events/{slug} for an invitation-kind event. Mirrors what
 * resources/views/events/public.blade.php and partials/public-invitation-meta.blade.php
 * actually render — see plans/android-app.md and the Slice A plan for the field-by-field
 * grounding. `invitation` carries InvitationCustomizationService::merge()'s output
 * near-verbatim (same shape Blade already depends on — not redesigned here), except media
 * paths are resolved to full URLs so the client never needs to know the storage/ convention.
 *
 * @mixin Event
 */
class PublicEventResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<string, mixed>  $invitation
     */
    public function __construct(
        Event $resource,
        private readonly array $invitation,
        private readonly bool $rsvpOpen,
        private readonly bool $rsvpPublicAvailable,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_time' => $this->event_time,
            'venue' => $this->venue,
            'location_name' => $this->location_name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'cover_image_url' => $this->cover_image_url,
            'accepts_contributions' => $this->acceptsContributions(),
            'contribution_amount' => $this->contribution_amount,
            'branding_removed' => $this->branding_removed,
            'rsvp_open' => $this->rsvpOpen,
            'rsvp_public_available' => $this->rsvpPublicAvailable,
            'invitation' => $this->resolvedInvitation(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedInvitation(): array
    {
        $invitation = $this->invitation;

        $media = $invitation['media'] ?? [];
        $media['gallery'] = $this->resolvePaths($media['gallery'] ?? []);
        $media['hero_portrait'] = $this->resolvePath($media['hero_portrait'] ?? null);
        $media['couple_photos'] = $this->resolvePaths($media['couple_photos'] ?? []);
        $invitation['media'] = $media;

        // Internal migration marker only — no Blade view reads it, nothing for a client to do
        // with it either.
        unset($invitation['schema_version']);

        return $invitation;
    }

    /**
     * @param  list<string|null>  $paths
     * @return list<string>
     */
    private function resolvePaths(array $paths): array
    {
        // Preserve empty-string placeholders (BFA's fixed-slot couple_photos array), matching
        // Blade's own @if($p) guard — do not drop or resolve empty entries.
        return array_map(fn ($path) => $this->resolvePath($path) ?? '', $paths);
    }

    private function resolvePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }

        // Already a full URL (or a non-storage reference) — leave it alone.
        if (str_contains($path, '://')) {
            return $path;
        }

        return asset('storage/'.$path);
    }
}
