<?php

namespace Database\Factories;

use App\Enums\SubscriptionTier;
use App\Models\InvitationTemplate;
use App\Support\InvitationLayoutVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvitationTemplate>
 */
class InvitationTemplateFactory extends Factory
{
    protected $model = InvitationTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'slug' => $slug,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'skin' => fake()->randomElement(['classic', 'minimal', 'botanical-blush']),
            'preview_image' => null,
            'default_theme' => [
                'primary' => '#1a2a4a',
                'accent' => '#1e47bb',
                'background' => '#fafafa',
                'font_heading_key' => 'system_ui',
                'font_body_key' => 'system_ui',
                'animation_subtle' => false,
            ],
            'default_sections' => [
                ['type' => 'hero', 'visible' => true],
                ['type' => 'details', 'visible' => true],
                ['type' => 'description', 'visible' => true],
                ['type' => 'story', 'visible' => true],
                ['type' => 'schedule', 'visible' => true],
                ['type' => 'rsvp', 'visible' => true],
            ],
            'is_active' => true,
            'is_featured' => false,
            'featured_sort_order' => 0,
            'sort_order' => 0,
            'min_subscription_tier' => SubscriptionTier::Base,
            'layout_variant' => InvitationLayoutVariant::STANDARD,
        ];
    }

    /**
     * Featured on the homepage. Carries a preview image because the homepage
     * scope skips featured rows that have none.
     */
    public function featured(int $order = 0): static
    {
        return $this->state(fn (): array => [
            'is_featured' => true,
            'featured_sort_order' => $order,
            'preview_image' => 'templates/'.fake()->unique()->slug(2).'.webp',
        ]);
    }
}
