<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * Phase 0.1 — proves /api/v1 resolves before Slice A adds anything real to call.
 * See plans/android-app.md and EventHostAndriodApp/implementation.md §0.1.
 */
class PingTest extends TestCase
{
    public function test_ping_returns_ok_status_with_a_parseable_timestamp(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'time']);

        // toIso8601String() must round-trip through a real parser, not just "looks like a string".
        $this->assertNotFalse(strtotime($response->json('time')));
    }

    public function test_ping_requires_no_authentication(): void
    {
        $this->getJson('/api/v1/ping')->assertOk();
    }
}
