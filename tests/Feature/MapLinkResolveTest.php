<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapLinkResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_a_short_link_to_coordinates(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/Test+Venue/@-15.4067000,28.2871000,17z',
            ]),
            '*' => Http::response('', 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('maps.resolve-link'), ['url' => 'https://maps.app.goo.gl/AbC123'])
            ->assertOk()
            ->assertJson(['latitude' => -15.4067, 'longitude' => 28.2871]);
    }

    public function test_follows_a_multi_hop_redirect_chain(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://goo.gl/maps/intermediate']),
            'goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/@-15.4067000,28.2871000,17z',
            ]),
            '*' => Http::response('', 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('maps.resolve-link'), ['url' => 'https://maps.app.goo.gl/AbC123'])
            ->assertOk()
            ->assertJson(['latitude' => -15.4067, 'longitude' => 28.2871]);
    }

    public function test_rejects_a_url_whose_host_is_not_allowlisted(): void
    {
        $user = User::factory()->create();
        Http::fake();

        $this->actingAs($user)
            ->postJson(route('maps.resolve-link'), ['url' => 'https://evil.example.com/maps/@1,2'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_redirect_chain_longer_than_five_hops_is_rejected(): void
    {
        $user = User::factory()->create();

        // Always redirects back to an allowed host, so the chain never terminates on its own.
        Http::fake([
            '*' => Http::response('', 302, ['Location' => 'https://goo.gl/maps/loops-forever']),
        ]);

        $this->actingAs($user)
            ->postJson(route('maps.resolve-link'), ['url' => 'https://maps.app.goo.gl/AbC123'])
            ->assertStatus(422);
    }

    public function test_a_resolved_link_with_no_coordinates_is_rejected(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/Some+Venue',
            ]),
            '*' => Http::response('', 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('maps.resolve-link'), ['url' => 'https://maps.app.goo.gl/AbC123'])
            ->assertStatus(422);
    }

    public function test_guests_cannot_call_the_resolver(): void
    {
        Http::fake();

        $this->postJson(route('maps.resolve-link'), ['url' => 'https://maps.app.goo.gl/AbC123'])
            ->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_it_is_rate_limited(): void
    {
        $user = User::factory()->create();
        Http::fake();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)
                ->postJson(route('maps.resolve-link'), ['url' => 'https://evil.example.com'])
                ->assertStatus(422);
        }

        $this->actingAs($user)
            ->postJson(route('maps.resolve-link'), ['url' => 'https://evil.example.com'])
            ->assertStatus(429);
    }
}
