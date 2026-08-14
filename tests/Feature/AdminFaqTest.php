<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Faq;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function supportAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('support');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'question' => 'How do I add a guest?',
            'answer' => 'Open the event, go to Guests, and click Add guest.',
            'placement' => 'homepage',
            'sort_order' => 10,
            'is_published' => '1',
        ], $overrides);
    }

    public function test_support_role_cannot_manage_faqs(): void
    {
        $this->actingAs($this->supportAdmin(), 'admin')
            ->get(route('admin.faqs.index'))
            ->assertForbidden();
    }

    public function test_super_admin_sees_the_faq_list(): void
    {
        $faq = Faq::factory()->create(['question' => 'Where is my invoice?']);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.faqs.index'))
            ->assertOk()
            ->assertSee($faq->question, escape: false);
    }

    public function test_admin_can_create_a_faq(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.faqs.store'), $this->payload())
            ->assertRedirect(route('admin.faqs.index'))
            ->assertSessionHas('status', 'faq-created');

        $this->assertDatabaseHas('faqs', [
            'question' => 'How do I add a guest?',
            'placement' => 'homepage',
            'sort_order' => 10,
            'is_published' => true,
        ]);
    }

    public function test_admin_can_update_a_faq(): void
    {
        $faq = Faq::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.faqs.update', $faq), $this->payload([
                'question' => 'Updated question?',
                'placement' => 'contact',
                'is_published' => '0',
            ]))
            ->assertRedirect(route('admin.faqs.index'))
            ->assertSessionHas('status', 'faq-updated');

        $faq->refresh();

        $this->assertSame('Updated question?', $faq->question);
        $this->assertSame('contact', $faq->placement);
        $this->assertFalse($faq->is_published);
    }

    public function test_admin_can_delete_a_faq(): void
    {
        $faq = Faq::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->delete(route('admin.faqs.destroy', $faq))
            ->assertRedirect(route('admin.faqs.index'))
            ->assertSessionHas('status', 'faq-deleted');

        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
    }

    public function test_a_blank_question_is_rejected(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.faqs.store'), $this->payload(['question' => '']))
            ->assertSessionHasErrors('question');

        $this->assertDatabaseCount('faqs', 0);
    }

    public function test_an_unknown_placement_is_rejected(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.faqs.store'), $this->payload(['placement' => 'pricing']))
            ->assertSessionHasErrors('placement');

        $this->assertDatabaseCount('faqs', 0);
    }
}
