<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\WeddingInvitationView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeddingInvitationViewTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): Event
    {
        return Event::factory()->make(array_merge([
            'name' => 'Kasuba & Tamara',
            'cover_image' => 'event-covers/cover.webp',
            'event_date' => '2027-08-14',
            'event_time' => '15:30:00',
            'venue' => "St. Mary's Chapel",
            'location_name' => 'Lusaka',
        ], $attributes));
    }

    private function weddingView(Event $event, array $invitation = []): WeddingInvitationView
    {
        return WeddingInvitationView::for($event, $invitation);
    }

    public function test_couple_photos_are_all_cover_when_none_are_stored(): void
    {
        $event = $this->event();
        $cover = $event->cover_image_url;

        $this->assertSame([$cover, $cover, $cover], $this->weddingView($event)->couplePhotos());
    }

    public function test_one_couple_photo_repeats_to_fill_three_slots(): void
    {
        $photos = $this->weddingView($this->event(), ['media' => ['couple_photos' => ['invitation-couple/1/a.webp']]])->couplePhotos();

        $a = asset('storage/invitation-couple/1/a.webp');
        $this->assertSame([$a, $a, $a], $photos);
    }

    public function test_two_couple_photos_repeat_the_last(): void
    {
        $photos = $this->weddingView($this->event(), ['media' => ['couple_photos' => [
            'invitation-couple/1/a.webp',
            '',
            'https://images.example.com/b.jpg',
        ]]])->couplePhotos();

        $this->assertSame([
            asset('storage/invitation-couple/1/a.webp'),
            'https://images.example.com/b.jpg',
            'https://images.example.com/b.jpg',
        ], $photos);
    }

    public function test_couple_photos_never_exceed_three(): void
    {
        $photos = $this->weddingView($this->event(), ['media' => ['couple_photos' => ['a.webp', 'b.webp', 'c.webp', 'd.webp']]])->couplePhotos();

        $this->assertCount(3, $photos);
        $this->assertSame(asset('storage/c.webp'), $photos[2]);
    }

    public function test_schedule_rows_are_trimmed_and_untitled_rows_dropped(): void
    {
        $rows = $this->weddingView($this->event(), ['content' => ['schedule' => [
            ['time' => ' 3:00 PM ', 'title' => ' Ceremony ', 'detail' => ' Chapel '],
            ['time' => '4:00 PM', 'title' => '   ', 'detail' => 'Nothing'],
            'not-a-row',
            ['title' => 'Reception'],
        ]]])->scheduleRows();

        $this->assertSame([
            ['time' => '3:00 PM', 'title' => 'Ceremony', 'detail' => 'Chapel'],
            ['time' => '', 'title' => 'Reception', 'detail' => ''],
        ], $rows);
    }

    public function test_time_line_formats_the_start_time(): void
    {
        $this->assertSame('3:30 PM', $this->weddingView($this->event())->timeLine());
    }

    public function test_time_line_is_null_without_an_event_time(): void
    {
        $this->assertNull($this->weddingView($this->event(['event_time' => null]))->timeLine());
    }

    public function test_date_line(): void
    {
        $this->assertSame('Saturday, 14 August 2027', $this->weddingView($this->event())->dateLine());
    }

    public function test_location_is_omitted_when_it_equals_the_venue(): void
    {
        $view = $this->weddingView($this->event(['venue' => ' Lusaka ', 'location_name' => 'Lusaka']));

        $this->assertSame('Lusaka', $view->venueLine());
        $this->assertSame('', $view->locationLine());
    }

    public function test_location_is_kept_when_it_differs_from_the_venue(): void
    {
        $this->assertSame('Lusaka', $this->weddingView($this->event())->locationLine());
    }

    public function test_names_and_story(): void
    {
        $view = $this->weddingView($this->event(), ['content' => ['story' => "  We met in Lusaka.\n  "]]);

        $this->assertSame(['Kasuba', 'Tamara'], $view->names());
        $this->assertSame('We met in Lusaka.', $view->story());
        $this->assertSame('', $this->weddingView($this->event())->story());
    }
}
