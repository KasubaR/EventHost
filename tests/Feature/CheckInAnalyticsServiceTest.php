<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\CheckInAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_arrivals_are_grouped_into_time_buckets(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $base = now()->startOfHour();

        Guest::factory()->for($event)->create(['checked_in_at' => $base->copy()->addMinutes(2)]);
        Guest::factory()->for($event)->create(['checked_in_at' => $base->copy()->addMinutes(10)]);
        Guest::factory()->for($event)->create(['checked_in_at' => $base->copy()->addMinutes(20)]);
        Guest::factory()->for($event)->create(['checked_in_at' => null]);

        $buckets = (new CheckInAnalyticsService)->arrivalsByBucket($event, 15);

        $this->assertCount(2, $buckets);
        $this->assertSame(2, $buckets[0]['count']);
        $this->assertSame(1, $buckets[1]['count']);
    }

    public function test_returns_empty_array_when_nobody_has_checked_in(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['checked_in_at' => null]);

        $buckets = (new CheckInAnalyticsService)->arrivalsByBucket($event);

        $this->assertSame([], $buckets);
    }
}
