<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/events/{event}/preview — host-only view of the event's real, current
 * invitation design, regardless of is_published/is_public. Deliberately independent
 * of PublicEventResource (not reused) even though the shape overlaps heavily: the
 * two endpoints have different gating (owner/staff vs. public) and different growth
 * trajectories, so a few lines of duplicated media-URL-resolution logic here is
 * preferred over coupling a guest-facing and host-facing resource together.
 *
 * @mixin Event
 */
class EventPreviewResource extends JsonResource
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
            'id' => $this->id,
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
            'is_published' => $this->is_published,
            'is_public' => $this->is_public,
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

        unset($invitation['schema_version']);

        return $invitation;
    }

    /**
     * @param  list<string|null>  $paths
     * @return list<string>
     */
    private function resolvePaths(array $paths): array
    {
        return array_map(fn ($path) => $this->resolvePath($path) ?? '', $paths);
    }

    private function resolvePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }

        if (str_contains($path, '://')) {
            return $path;
        }

        return asset('storage/'.$path);
    }
}
