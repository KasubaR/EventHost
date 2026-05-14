<?php

namespace Tests\Unit\Support;

use App\Support\SafeIntendedUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

class SafeIntendedUrlTest extends TestCase
{
    public function test_external_https_url_is_rejected(): void
    {
        $request = Request::create('http://localhost/');

        $this->assertFalse(SafeIntendedUrl::isSafeIntendedTarget('https://evil.com/x', $request));
    }

    public function test_protocol_relative_external_url_is_rejected(): void
    {
        $request = Request::create('http://localhost/');

        $this->assertFalse(SafeIntendedUrl::isSafeIntendedTarget('//evil.com/path', $request));
    }

    public function test_same_host_absolute_url_is_accepted(): void
    {
        $request = Request::create('http://localhost/foo');

        $this->assertTrue(SafeIntendedUrl::isSafeIntendedTarget('http://localhost/bar', $request));
    }

    public function test_relative_path_is_accepted(): void
    {
        $request = Request::create('http://localhost/foo');

        $this->assertTrue(SafeIntendedUrl::isSafeIntendedTarget('/profile', $request));
    }

    public function test_non_http_scheme_without_host_is_rejected(): void
    {
        $request = Request::create('http://localhost/');

        $this->assertFalse(SafeIntendedUrl::isSafeIntendedTarget('javascript:alert(1)', $request));
    }

    public function test_http_with_missing_host_is_rejected(): void
    {
        $request = Request::create('http://localhost/');

        $this->assertFalse(SafeIntendedUrl::isSafeIntendedTarget('http:bad', $request));
    }
}
