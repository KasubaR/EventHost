<?php

namespace Database\Seeders;

use App\Enums\SubscriptionTier;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Support\InvitationLayoutVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class InvitationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['slug' => 'wedding', 'name' => 'Wedding', 'sort_order' => 10],
            ['slug' => 'birthday', 'name' => 'Birthday', 'sort_order' => 20],
            ['slug' => 'graduation', 'name' => 'Graduation', 'sort_order' => 30],
            ['slug' => 'corporate', 'name' => 'Corporate', 'sort_order' => 40],
            ['slug' => 'baby-shower', 'name' => 'Baby Shower', 'sort_order' => 50],
            ['slug' => 'funeral-memorial', 'name' => 'Funeral/Memorial', 'sort_order' => 60],
            ['slug' => 'church', 'name' => 'Church', 'sort_order' => 70],
        ])->mapWithKeys(function (array $row) {
            $cat = InvitationTemplateCategory::query()->updateOrCreate(
                ['slug' => $row['slug']],
                ['name' => $row['name'], 'sort_order' => $row['sort_order']]
            );

            return [$row['slug'] => $cat];
        });

        $templates = [
            [
                'slug' => 'modern-minimal',
                'name' => 'Modern Minimal',
                'description' => 'Clean white wedding layout with stacked names, live countdown, detail columns, story, photo grid, and minimal RSVP. Pro minimum.',
                'skin' => 'classic',
                'sort_order' => 57,
                'min_subscription_tier' => SubscriptionTier::Pro->value,
                'layout_variant' => InvitationLayoutVariant::MODERN_MINIMAL,
                'category_slugs' => ['wedding', 'corporate', 'birthday'],
                'default_theme' => [
                    'primary' => '#1a1a1a',
                    'accent' => '#c0a080',
                    'background' => '#ffffff',
                    'font_heading_key' => 'dm_sans',
                    'font_body_key' => 'inter',
                    'animation_subtle' => false,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'countdown', 'visible' => true],
                    ['type' => 'details', 'visible' => true],
                    ['type' => 'story', 'visible' => true],
                    ['type' => 'gallery', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                ],
            ],
            [
                'slug' => 'wedding-invitation',
                'name' => 'Ivory & Gold Wedding',
                'description' => 'Elegant full-page wedding layout with a cinematic hero, save-the-date band, couple portrait grid, story panel, celebration details, and masonry gallery. Pro minimum.',
                'skin' => 'classic',
                'sort_order' => 55,
                'min_subscription_tier' => SubscriptionTier::Pro->value,
                'layout_variant' => InvitationLayoutVariant::WEDDING_INVITATION,
                'category_slugs' => ['wedding'],
                'default_theme' => [
                    'primary' => '#2c2520',
                    'accent' => '#b8965a',
                    'background' => '#faf6f0',
                    'font_heading_key' => 'playfair',
                    'font_body_key' => 'jost',
                    'animation_subtle' => true,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'description', 'visible' => true],
                    ['type' => 'story', 'visible' => true],
                    ['type' => 'details', 'visible' => true],
                    ['type' => 'gallery', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                ],
            ],
            [
                'slug' => 'wedding-invitation-2',
                'name' => 'Noir & Gold Wedding',
                'description' => 'Dramatic art-deco wedding with split hero, formal invitation card, quote interlude, vertical timeline programme, and split gallery on an onyx-and-gold palette. Pro minimum.',
                'skin' => 'classic',
                'sort_order' => 56,
                'min_subscription_tier' => SubscriptionTier::Pro->value,
                'layout_variant' => InvitationLayoutVariant::WEDDING_INVITATION_NOIR,
                'category_slugs' => ['wedding'],
                'default_theme' => [
                    'primary' => '#f5eedc',
                    'accent' => '#c8973f',
                    'background' => '#0d0b09',
                    'font_heading_key' => 'bodoni_moda',
                    'font_body_key' => 'eb_garamond',
                    'animation_subtle' => true,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'description', 'visible' => true],
                    ['type' => 'story', 'visible' => true],
                    ['type' => 'schedule', 'visible' => true],
                    ['type' => 'gallery', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                ],
            ],
            [
                'slug' => 'event-invite',
                'name' => 'Blush Celebration Card',
                'description' => 'Romantic blush-and-gold invitation card with fairy lights, script headlines, and optional speaker and theme details. Included on the base plan.',
                'skin' => 'classic',
                'sort_order' => 15,
                'layout_variant' => InvitationLayoutVariant::EVENT_INVITE,
                'category_slugs' => [
                    'wedding',
                    'birthday',
                    'graduation',
                    'corporate',
                    'baby-shower',
                    'church',
                ],
                'default_theme' => [
                    'primary' => '#5a1a20',
                    'accent' => '#e8b4b8',
                    'background' => '#f0e6e6',
                    'font_heading_key' => 'great_vibes',
                    'font_body_key' => 'montserrat',
                    'animation_subtle' => false,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                ],
            ],
            [
                'slug' => 'slate-minimal',
                'name' => 'Classic',
                'description' => 'Standard invitation layout shared by all base accounts. Differentiate your page with colors, fonts, and sections under Design.',
                'skin' => 'classic',
                'sort_order' => 10,
                'category_slugs' => [
                    'wedding',
                    'birthday',
                    'graduation',
                    'corporate',
                    'baby-shower',
                    'funeral-memorial',
                    'church',
                ],
                'default_theme' => [
                    'primary' => '#1e293b',
                    'accent' => '#0ea5e9',
                    'background' => '#ffffff',
                    'font_heading_key' => 'inter',
                    'font_body_key' => 'inter',
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
            ],
            [
                'slug' => 'pro-magazine',
                'name' => 'Pro Magazine',
                'description' => 'Bold editorial layout with a full-bleed hero, magazine-style masthead, and strong typographic hierarchy. Pro minimum.',
                'skin' => 'classic',
                'sort_order' => 60,
                'min_subscription_tier' => SubscriptionTier::Pro->value,
                'layout_variant' => InvitationLayoutVariant::PRO_MAGAZINE,
                'category_slugs' => ['wedding', 'corporate', 'graduation', 'church'],
                'default_theme' => [
                    'primary' => '#1c1917',
                    'accent' => '#dc2626',
                    'background' => '#fafaf9',
                    'font_heading_key' => 'playfair',
                    'font_body_key' => 'dm_sans',
                    'animation_subtle' => false,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'details', 'visible' => true],
                    ['type' => 'description', 'visible' => true],
                    ['type' => 'story', 'visible' => true],
                    ['type' => 'schedule', 'visible' => true],
                    ['type' => 'countdown', 'visible' => true],
                    ['type' => 'gallery', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                ],
            ],
            [
                'slug' => 'graduation-template-2-botanical-blush',
                'name' => 'Botanical Blush Graduation',
                'description' => 'Botanical blush graduation layout — split hero, serif headlines, tile details (matches reference design). Pro minimum.',
                'skin' => 'classic',
                'sort_order' => 65,
                'min_subscription_tier' => SubscriptionTier::Pro->value,
                'layout_variant' => InvitationLayoutVariant::BOTANICAL_GRADUATION,
                'category_slugs' => ['graduation', 'wedding', 'birthday'],
                'default_theme' => [
                    'primary' => '#2C2420',
                    'accent' => '#C4847A',
                    'background' => '#FDFAF6',
                    'font_heading_key' => 'libre_baskerville',
                    'font_body_key' => 'jost',
                    'animation_subtle' => false,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'countdown', 'visible' => true],
                    ['type' => 'details', 'visible' => true],
                    ['type' => 'description', 'visible' => true],
                    ['type' => 'story', 'visible' => true],
                    ['type' => 'schedule', 'visible' => true],
                    ['type' => 'gallery', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                ],
            ],
            [
                'slug' => 'beauty-for-ashes',
                'name' => 'Beauty for Ashes (Conference)',
                'description' => 'Dramatic purple-and-gold conference layout with speaker grid, rich typography, and jewel-tone panels. Pro minimum.',
                'skin' => 'classic',
                'sort_order' => 70,
                'min_subscription_tier' => SubscriptionTier::Pro->value,
                'layout_variant' => InvitationLayoutVariant::BEAUTY_FOR_ASHES,
                'category_slugs' => ['church'],
                'default_theme' => [
                    'primary' => '#0e0020',
                    'accent' => '#f5c518',
                    'background' => '#1a003a',
                    'font_heading_key' => 'cormorant_garamond',
                    'font_body_key' => 'lato',
                    'animation_subtle' => false,
                ],
                'default_sections' => [
                    ['type' => 'hero', 'visible' => true],
                    ['type' => 'gallery', 'visible' => true],
                    ['type' => 'story', 'visible' => true],
                    ['type' => 'details', 'visible' => true],
                    ['type' => 'schedule', 'visible' => true],
                    ['type' => 'rsvp', 'visible' => true],
                    ['type' => 'description', 'visible' => true],
                ],
            ],
        ];

        $keptSlugs = collect($templates)->pluck('slug')->all();

        foreach ($templates as $row) {
            $categorySlugs = $row['category_slugs'];
            unset($row['category_slugs']);

            $row = array_merge([
                'min_subscription_tier' => SubscriptionTier::Base->value,
                'layout_variant' => InvitationLayoutVariant::STANDARD,
            ], $row);

            $tpl = InvitationTemplate::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );

            $catIds = collect($categorySlugs)
                ->map(fn (string $s) => $categories[$s]->id ?? null)
                ->filter()
                ->values()
                ->all();

            $tpl->categories()->sync($catIds);
        }

        $orphanIds = InvitationTemplate::query()
            ->whereNotIn('slug', $keptSlugs)
            ->pluck('id');

        if ($orphanIds->isNotEmpty()) {
            $base = InvitationTemplate::query()->where('slug', 'slate-minimal')->first();
            if ($base !== null) {
                Event::query()
                    ->whereIn('invitation_template_id', $orphanIds)
                    ->update(['invitation_template_id' => $base->id]);
            }

            InvitationTemplate::query()->whereIn('id', $orphanIds)->delete();
        }

        InvitationTemplateCategory::query()
            ->whereNotIn('slug', $categories->keys()->all())
            ->delete();

        Cache::forget('tpl_categories');
    }
}
