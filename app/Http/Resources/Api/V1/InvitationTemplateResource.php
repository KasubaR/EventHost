<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\InvitationTemplate
 */
class InvitationTemplateResource extends JsonResource
{
    public static $wrap = null;

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
            'preview_image_url' => $this->preview_image_url,
            'skin' => $this->skin,
            'layout_variant' => $this->layout_variant,
            'min_subscription_tier' => $this->requiredTier()->value,
            'category_slugs' => $this->whenLoaded('categories', fn () => $this->categories->pluck('slug')->values()),
        ];
    }
}
