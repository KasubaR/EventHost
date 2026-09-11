<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function csvFile(string $contents, string $name = 'guests.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'guest_import');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_import_creates_guests_and_reports_counts(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['email' => 'existing@example.test']);

        $csv = "name,email,phone,group,table\n"
            ."Jane Doe,jane@example.test,,,\n"
            ."Existing Person,existing@example.test,,,\n"; // duplicate email -> skipped

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->post("/api/v1/host/events/{$event->id}/guests/import", [
                'file' => $this->csvFile($csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('capped', 0);

        $this->assertNotNull(Guest::where('email', 'jane@example.test')->first());
    }

    public function test_template_download_returns_a_csv(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/host/events/{$event->id}/guests/import/template");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_accepted_manager_staff_can_import(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $csv = "name,email,phone,group,table\nManager Import,mi@example.test,,,\n";

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->post("/api/v1/host/events/{$event->id}/guests/import", ['file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertJsonPath('created', 1);
    }
}
