<?php

namespace App\Models;

use App\Enums\SubscriptionTier;
use App\Services\InvitationCustomizationService;
use Database\Factories\InvitationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvitationTemplate extends Model
{
    /** @use HasFactory<InvitationTemplateFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'skin',
        'preview_image',
        'default_theme',
        'default_sections',
        'is_active',
        'sort_order',
        'min_subscription_tier',
        'layout_variant',
    ];

    /**
     * @return BelongsToMany<InvitationTemplateCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            InvitationTemplateCategory::class,
            'inv_tpl_cat'
        );
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_theme' => 'array',
            'default_sections' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'min_subscription_tier' => SubscriptionTier::class,
        ];
    }

    public function getPreviewImageUrlAttribute(): ?string
    {
        if ($this->preview_image === null || $this->preview_image === '') {
            return null;
        }

        return asset('storage/'.$this->preview_image);
    }

    /**
     * Build an in-memory sample Event for template preview pages.
     *
     * This method never queries the database and never returns null.
     * The returned Event is not persisted — do not call save() on it.
     * If this method is ever refactored to query the DB, update
     * TemplateLibraryController::preview() to handle a missing row.
     */
    public function previewSampleEvent(): Event
    {
        $starts = now()->addWeeks(3)->setTime(17, 0);

        $event = new Event([
            'invitation_template_id' => $this->id,
            'name' => 'Alex & Jordan\'s Celebration',
            'event_type' => 'wedding',
            'description' => "We're tying the knot and would love for you to celebrate with us.\n\nDress code: semi-formal.",
            'venue' => 'Riverside Conservatory',
            'location_name' => 'Portland, OR',
            'latitude' => 45.5152,
            'longitude' => -122.6784,
            'is_public' => true,
            'guest_limit' => 120,
            'allow_plus_one' => true,
            'show_guest_list' => false,
            'is_published' => true,
            'cover_image' => null,
            'event_date' => $starts->copy()->startOfDay(),
            'event_time' => $starts->format('H:i:s'),
            'rsvp_deadline' => now()->addWeeks(2),
        ]);

        $event->setRelation('invitationTemplate', $this);

        if ($this->slug === 'event-invite') {
            $event->name = "Mukuba's";
            $event->event_type = 'birthday';
            $event->description = 'A joyful birthday lunch celebration.';
            $event->venue = 'Kabulonga Roan Road 45';
            $event->location_name = 'Lusaka, Zambia';
            $event->event_date = now()->addWeeks(2)->startOfDay();
            $event->event_time = '13:30:00';
            $event->invitation_customization = [
                'schema_version' => InvitationCustomizationService::CURRENT_SCHEMA_VERSION,
                'content' => [
                    'ei_color_theme' => 'Denim and Brown',
                    'ei_guest_speaker' => 'Lucy Mulenga',
                    'ei_mc' => 'Rabecca and Natasha',
                ],
            ];
        } elseif ($this->slug === 'beauty-for-ashes') {
            $event->name = 'Beauty For Ashes';
            $event->event_type = 'church';
            $event->description = "New Breed Christian Ministries International\n\nNew Breed of Women Conference — join us for worship, teaching, and fellowship.";
            $event->venue = 'Off Lime Road, Downtown Area, Lusaka';
            $event->location_name = 'Lusaka, Zambia';
            $event->invitation_customization = [
                'schema_version' => InvitationCustomizationService::CURRENT_SCHEMA_VERSION,
                'content' => [
                    'story' => 'A time to reset to the set Standard, as we go back to the Beautiful Beginning',
                    'schedule' => [
                        ['time' => '09:30', 'title' => 'Doors open', 'detail' => 'Registration & refreshments'],
                        ['time' => '10:00', 'title' => 'Main session', 'detail' => 'Worship and word'],
                    ],
                    'speaker_cards' => [
                        ['role' => 'Dr Prophetess', 'name' => 'Christine Mwelwa'],
                        ['role' => 'Prophetess', 'name' => 'Nomsa Maida'],
                        ['role' => 'Minister', 'name' => 'Temwani'],
                        ['role' => 'Dr Prophetess', 'name' => 'Tiko Silweya'],
                    ],
                    'venue_note' => '3rd gate after the curve — right next to CM Bakery.',
                    'bfa_conference_theme' => 'All Shades of Purple',
                    'bfa_dress_code' => 'Elegant Attire',
                    'bfa_presenter_line' => 'New Breed Christian Ministries International',
                    'bfa_presents_line' => 'Presents',
                    'bfa_tagline_bar' => 'New Breed of Women Conference',
                    'contact_phone_primary' => '+260 975 521 619',
                    'contact_phone_secondary' => '+260 974 887 453',
                ],
            ];
        } else {
            $event->invitation_customization = [
                'schema_version' => InvitationCustomizationService::CURRENT_SCHEMA_VERSION,
                'content' => [
                    'story' => 'From a chance meeting to this celebration — we would love you to share the day with us.',
                    'schedule' => [
                        ['time' => '4:00 PM', 'title' => 'Ceremony', 'detail' => 'Garden conservatory entrance'],
                        ['time' => '6:00 PM', 'title' => 'Reception', 'detail' => 'Dinner, dancing, and toasts'],
                    ],
                ],
            ];
        }

        return $event;
    }

    public function requiredTier(): SubscriptionTier
    {
        $tier = $this->min_subscription_tier;

        return $tier instanceof SubscriptionTier
            ? $tier
            : SubscriptionTier::normalize(is_string($tier) ? $tier : null);
    }

    public function requiredTierRank(): int
    {
        return $this->requiredTier()->rank();
    }
}
