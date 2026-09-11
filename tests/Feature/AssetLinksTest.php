<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Android App Links verification file (Digital Asset Links). Must resolve at this exact,
 * unprefixed path with valid JSON — Android's verifier does not follow redirects or accept a
 * non-200. See plans/android-app.md and EventHostAndriodApp/implementation.md §0.1.
 */
class AssetLinksTest extends TestCase
{
    public function test_it_serves_valid_asset_links_json_with_the_configured_package_name(): void
    {
        $response = $this->getJson('/.well-known/assetlinks.json');

        $response->assertOk();

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload);

        foreach ($payload as $entry) {
            $this->assertSame(['delegate_permission/common.handle_all_urls'], $entry['relation']);
            $this->assertSame('android_app', $entry['target']['namespace']);
            $this->assertSame(config('android.package_name'), $entry['target']['package_name']);
            $this->assertNotEmpty($entry['target']['sha256_cert_fingerprints']);
        }
    }

    public function test_it_falls_back_to_a_placeholder_fingerprint_when_none_is_configured(): void
    {
        config(['android.sha256_fingerprints' => []]);

        $response = $this->getJson('/.well-known/assetlinks.json');

        $response->assertOk();
        $this->assertNotEmpty($response->json('0.target.sha256_cert_fingerprints'));
    }

    public function test_it_requires_no_authentication(): void
    {
        $this->getJson('/.well-known/assetlinks.json')->assertOk();
    }
}
