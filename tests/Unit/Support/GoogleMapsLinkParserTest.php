<?php

namespace Tests\Unit\Support;

use App\Support\GoogleMapsLinkParser;
use Tests\TestCase;

class GoogleMapsLinkParserTest extends TestCase
{
    public function test_maps_app_goo_gl_is_a_short_link(): void
    {
        $this->assertTrue(GoogleMapsLinkParser::isShortLink('https://maps.app.goo.gl/AbC123'));
    }

    public function test_goo_gl_maps_path_is_a_short_link(): void
    {
        $this->assertTrue(GoogleMapsLinkParser::isShortLink('https://goo.gl/maps/AbC123'));
    }

    public function test_bare_goo_gl_without_maps_path_is_not_a_short_link(): void
    {
        // goo.gl also serves unrelated short links — the /maps/ prefix is what makes it ours.
        $this->assertFalse(GoogleMapsLinkParser::isShortLink('https://goo.gl/somethingElse'));
    }

    public function test_full_google_maps_url_is_not_a_short_link(): void
    {
        $this->assertFalse(GoogleMapsLinkParser::isShortLink('https://www.google.com/maps/@-15.4,28.2,17z'));
    }

    public function test_pin_coordinates_are_preferred_over_viewport_center(): void
    {
        // A "place" link where the pin and the panned-to viewport center legitimately disagree.
        $url = 'https://www.google.com/maps/place/Test+Venue/@-15.0,28.0,17z/data=!3d-15.4067000!4d28.2871000';

        $coords = GoogleMapsLinkParser::extractCoordinates($url);

        $this->assertSame(-15.4067, $coords['lat']);
        $this->assertSame(28.2871, $coords['lng']);
    }

    public function test_viewport_center_is_used_when_no_pin_coordinates_present(): void
    {
        $coords = GoogleMapsLinkParser::extractCoordinates('https://www.google.com/maps/@-15.4067,28.2871,17z');

        $this->assertSame(-15.4067, $coords['lat']);
        $this->assertSame(28.2871, $coords['lng']);
    }

    public function test_legacy_query_format_is_parsed(): void
    {
        $coords = GoogleMapsLinkParser::extractCoordinates('https://maps.google.com/maps?q=-15.4067,28.2871');

        $this->assertSame(-15.4067, $coords['lat']);
        $this->assertSame(28.2871, $coords['lng']);
    }

    public function test_url_with_no_coordinates_returns_null(): void
    {
        $this->assertNull(GoogleMapsLinkParser::extractCoordinates('https://www.google.com/maps/place/Some+Venue'));
    }

    public function test_search_api_ll_and_center_formats_are_parsed(): void
    {
        foreach ([
            'https://www.google.com/maps/search/?api=1&query=-15.4067,28.2871',
            'https://www.google.com/maps/search/?api=1&query=-15.4067%2C28.2871',
            'https://maps.google.com/?ll=-15.4067,28.2871&z=15',
            'https://www.google.com/maps?center=-15.4067,28.2871',
        ] as $url) {
            $coords = GoogleMapsLinkParser::extractCoordinates($url);

            $this->assertSame(-15.4067, $coords['lat'], $url);
            $this->assertSame(28.2871, $coords['lng'], $url);
        }
    }

    public function test_place_name_is_extracted_and_decoded(): void
    {
        $this->assertSame(
            "Taj Pamodzi Hotel & Spa",
            GoogleMapsLinkParser::extractPlaceName('https://www.google.com/maps/place/Taj+Pamodzi+Hotel+%26+Spa/@-15.4,28.3,17z')
        );
        $this->assertSame(
            'Some Venue',
            GoogleMapsLinkParser::extractPlaceName('https://www.google.com/maps/place/Some+Venue')
        );
    }

    public function test_place_name_is_null_when_absent_or_just_coordinates(): void
    {
        $this->assertNull(GoogleMapsLinkParser::extractPlaceName('https://www.google.com/maps/@-15.4,28.3,17z'));
        $this->assertNull(GoogleMapsLinkParser::extractPlaceName('https://www.google.com/maps/place/-15.4067,28.2871/@-15.4,28.3,17z'));
    }

    public function test_regional_google_domains_are_allowed_hops_but_lookalikes_are_not(): void
    {
        $this->assertTrue(GoogleMapsLinkParser::isAllowedHop('https://www.google.co.zm/maps/@1,2,3z'));
        $this->assertTrue(GoogleMapsLinkParser::isAllowedHop('https://maps.google.co.za/maps?q=1.5,2.5'));
        $this->assertFalse(GoogleMapsLinkParser::isAllowedHop('https://google.co.zm.evil.com/maps'));
        $this->assertFalse(GoogleMapsLinkParser::isAllowedHop('https://evilgoogle.co.zm/maps'));
    }

    public function test_allowed_hops_include_short_link_hosts_and_google_domains(): void
    {
        $this->assertTrue(GoogleMapsLinkParser::isAllowedHop('https://goo.gl/maps/xyz'));
        $this->assertTrue(GoogleMapsLinkParser::isAllowedHop('https://maps.app.goo.gl/xyz'));
        $this->assertTrue(GoogleMapsLinkParser::isAllowedHop('https://www.google.com/maps/@1,2,3z'));
        $this->assertTrue(GoogleMapsLinkParser::isAllowedHop('https://google.com/maps/@1,2,3z'));
    }

    public function test_unrelated_hosts_are_not_allowed_hops(): void
    {
        // The SSRF guard: a malicious short link could try to redirect anywhere, including an
        // internal address or a lookalike domain — neither may ever be followed.
        $this->assertFalse(GoogleMapsLinkParser::isAllowedHop('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(GoogleMapsLinkParser::isAllowedHop('https://evilgoogle.com/maps'));
        $this->assertFalse(GoogleMapsLinkParser::isAllowedHop('https://notgoogle.com'));
    }
}
