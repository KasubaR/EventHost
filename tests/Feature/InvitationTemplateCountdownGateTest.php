<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\InvitationTemplate;
use App\Support\InvitationLayoutVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The homepage markets "countdown timer" as a Pro feature, but there is no
 * standalone gate for it — a host only gets one because their template's
 * layout happens to render that section. This is the invariant that keeps
 * the promise true: InvitationTemplate refuses to save a countdown-capable
 * layout below Pro. See InvitationTemplate::booted() and
 * InvitationLayoutVariant::hasCountdownSection().
 */
class InvitationTemplateCountdownGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_countdown_capable_layout_cannot_be_saved_below_pro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('renders a countdown section');

        InvitationTemplate::factory()->create([
            'layout_variant' => InvitationLayoutVariant::MODERN_MINIMAL,
            'min_subscription_tier' => SubscriptionTier::Base,
        ]);
    }

    public function test_a_countdown_capable_layout_cannot_be_downgraded_below_pro(): void
    {
        $template = InvitationTemplate::factory()->create([
            'layout_variant' => InvitationLayoutVariant::MODERN_MINIMAL,
            'min_subscription_tier' => SubscriptionTier::Pro,
        ]);

        $this->expectException(RuntimeException::class);

        $template->update(['min_subscription_tier' => SubscriptionTier::Base]);
    }

    public function test_a_countdown_capable_layout_saves_fine_at_pro_or_above(): void
    {
        $template = InvitationTemplate::factory()->create([
            'layout_variant' => InvitationLayoutVariant::BOTANICAL_GRADUATION,
            'min_subscription_tier' => SubscriptionTier::Pro,
        ]);

        $this->assertSame(SubscriptionTier::Pro, $template->fresh()->min_subscription_tier);

        // Pro+ and above are fine too — only below Pro is refused.
        $template->update(['min_subscription_tier' => SubscriptionTier::ProPlus]);
        $this->assertSame(SubscriptionTier::ProPlus, $template->fresh()->min_subscription_tier);
    }

    public function test_a_layout_without_a_countdown_section_is_unaffected(): void
    {
        $template = InvitationTemplate::factory()->create([
            'layout_variant' => InvitationLayoutVariant::STANDARD,
            'min_subscription_tier' => SubscriptionTier::Base,
        ]);

        $this->assertSame(SubscriptionTier::Base, $template->fresh()->min_subscription_tier);
    }

    /**
     * Locks in exactly which layouts render a countdown today — if a new
     * layout adds the section, this test fails and points here rather than
     * the gap surfacing as a silent broken promise on the homepage.
     */
    public function test_known_countdown_capable_layouts(): void
    {
        $this->assertTrue(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::MODERN_MINIMAL));
        $this->assertTrue(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::PRO_MAGAZINE));
        $this->assertTrue(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::BOTANICAL_GRADUATION));

        $this->assertFalse(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::STANDARD));
        $this->assertFalse(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::BEAUTY_FOR_ASHES));
        $this->assertFalse(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::EVENT_INVITE));
        $this->assertFalse(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::WEDDING_INVITATION));
        $this->assertFalse(InvitationLayoutVariant::hasCountdownSection(InvitationLayoutVariant::WEDDING_INVITATION_NOIR));
    }
}
