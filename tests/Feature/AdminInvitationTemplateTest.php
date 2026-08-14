<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\InvitationTemplate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminInvitationTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
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

    public function test_support_role_cannot_manage_templates(): void
    {
        $this->actingAs($this->supportAdmin(), 'admin')
            ->get(route('admin.templates.index'))
            ->assertForbidden();
    }

    public function test_super_admin_sees_the_template_list(): void
    {
        $tpl = InvitationTemplate::query()->firstOrFail();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.templates.index'))
            ->assertOk()
            ->assertSee($tpl->name, escape: false);
    }

    public function test_admin_can_upload_a_preview_image_and_feature_the_template(): void
    {
        $tpl = InvitationTemplate::factory()->create(['preview_image' => null]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.templates.update', $tpl), [
                'is_featured' => '1',
                'featured_sort_order' => '10',
                'preview_image' => UploadedFile::fake()->image('cover.jpg', 900, 1200),
            ])
            ->assertRedirect(route('admin.templates.index'));

        $tpl->refresh();

        $this->assertTrue($tpl->is_featured);
        $this->assertSame(10, $tpl->featured_sort_order);
        $this->assertNotNull($tpl->preview_image);
        $this->assertStringStartsWith('templates/', $tpl->preview_image);
        $this->assertStringEndsWith('.webp', $tpl->preview_image);
        Storage::disk('public')->assertExists($tpl->preview_image);
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        Storage::disk('public')->put('templates/old.webp', 'stale');
        $tpl = InvitationTemplate::factory()->create(['preview_image' => 'templates/old.webp']);

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.templates.update', $tpl), [
                'is_featured' => '0',
                'featured_sort_order' => '0',
                'preview_image' => UploadedFile::fake()->image('new.jpg', 900, 1200),
            ])
            ->assertRedirect(route('admin.templates.index'));

        Storage::disk('public')->assertMissing('templates/old.webp');
        Storage::disk('public')->assertExists($tpl->refresh()->preview_image);
    }

    public function test_a_template_without_an_image_cannot_be_featured(): void
    {
        $tpl = InvitationTemplate::factory()->create(['preview_image' => null]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.templates.update', $tpl), [
                'is_featured' => '1',
                'featured_sort_order' => '0',
            ])
            ->assertSessionHasErrors('preview_image');

        $this->assertFalse($tpl->refresh()->is_featured);
    }

    public function test_removing_the_image_also_unfeatures_the_template(): void
    {
        Storage::disk('public')->put('templates/live.webp', 'bytes');
        $tpl = InvitationTemplate::factory()->create([
            'preview_image' => 'templates/live.webp',
            'is_featured' => true,
            'featured_sort_order' => 5,
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->delete(route('admin.templates.image.destroy', $tpl))
            ->assertRedirect(route('admin.templates.index'));

        $tpl->refresh();

        $this->assertNull($tpl->preview_image);
        $this->assertFalse($tpl->is_featured);
        Storage::disk('public')->assertMissing('templates/live.webp');
    }
}
