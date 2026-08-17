<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_renders_the_branded_404(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee("We couldn't find that page", escape: false)
            ->assertSee("the event isn't public", escape: false)
            ->assertSee(route('home'), escape: false)
            ->assertSee(route('events.discover'), escape: false)
            ->assertSee(asset('css/errors.css'), escape: false)
            ->assertSee('EventHost', false);
    }

    public function test_a_missing_page_returns_json_when_requested(): void
    {
        $this->getJson('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertDontSee('Page not found')
            ->assertDontSee("We couldn't find that page");
    }
}
