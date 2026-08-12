<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_tier_owner_is_redirected_to_billing_from_tables_index(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('events.tables.index', $event))
            ->assertRedirect(route('billing.show'));
    }

    public function test_pro_owner_can_create_a_table_with_a_generated_code(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.tables.store', $event), ['label' => 'Table 5'])
            ->assertRedirect(route('events.tables.index', $event));

        $table = EventTable::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($table);
        $this->assertSame('Table 5', $table->label);
        $this->assertSame(8, strlen($table->code));
    }

    public function test_base_tier_owner_cannot_create_a_table(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.tables.store', $event), ['label' => 'Table 1'])
            ->assertForbidden();

        $this->assertSame(0, EventTable::query()->where('event_id', $event->id)->count());
    }

    public function test_owner_can_rename_and_delete_a_table(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Old label']);

        $this->actingAs($owner)
            ->patch(route('events.tables.update', ['event' => $event, 'table' => $table]), ['label' => 'New label'])
            ->assertRedirect(route('events.tables.index', $event));

        $this->assertSame('New label', $table->fresh()->label);

        $this->actingAs($owner)
            ->delete(route('events.tables.destroy', ['event' => $event, 'table' => $table]))
            ->assertRedirect(route('events.tables.index', $event));

        $this->assertNull(EventTable::find($table->id));
    }

    public function test_non_owner_cannot_manage_another_hosts_tables(): void
    {
        $owner = User::factory()->pro()->create();
        $stranger = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create();

        $this->actingAs($stranger)
            ->get(route('events.tables.index', $event))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->delete(route('events.tables.destroy', ['event' => $event, 'table' => $table]))
            ->assertForbidden();
    }

    public function test_table_qr_endpoint_returns_svg(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create();

        $response = $this->actingAs($owner)
            ->get(route('events.tables.qr', ['event' => $event, 'table' => $table]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
    }
}
