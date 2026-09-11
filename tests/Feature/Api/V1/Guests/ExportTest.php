<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_csv_export_returns_csv_content_type(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Export Me']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/host/events/{$event->id}/guests/export");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Export Me', $response->streamedContent());
    }

    public function test_csv_export_respects_checked_in_filter(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Checked In', 'checked_in_at' => now()]);
        Guest::factory()->for($event)->create(['name' => 'Not Checked']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/host/events/{$event->id}/guests/export?checked_in=yes");

        $content = $response->streamedContent();
        $this->assertStringContainsString('Checked In', $content);
        $this->assertStringNotContainsString('Not Checked', $content);
    }

    public function test_pdf_export_returns_pdf_content_type(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/host/events/{$event->id}/guests/export-pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->get("/api/v1/host/events/{$event->id}/guests/export")
            ->assertForbidden();
    }
}
