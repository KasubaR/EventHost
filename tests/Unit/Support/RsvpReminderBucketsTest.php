<?php

namespace Tests\Unit\Support;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Support\RsvpReminderBuckets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RsvpReminderBucketsTest extends TestCase
{
    use RefreshDatabase;

    public static function normalizeProvider(): array
    {
        return [
            'null' => [null, []],
            'empty string' => ['', []],
            'invalid json string' => ['not-json', []],
            'associative array drops non scalars' => [[7 => '7'], ['7']],
            'filters unknown keeps allowed order' => [['x', '7', '7', '99', '3'], ['7', '3']],
            'json array string' => ['["7","bogus","1"]', ['7', '1']],
            'integer elements become strings' => [[7, 3], ['7', '3']],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize_filters_to_allowed_buckets(mixed $input, array $expected): void
    {
        $this->assertSame($expected, RsvpReminderBuckets::normalize($input));
    }

    public function test_with_bucket_appended_rejects_unknown_bucket(): void
    {
        $this->assertSame(
            ['7'],
            RsvpReminderBuckets::withBucketAppended(['7'], 'invalid')
        );
    }

    public function test_with_bucket_appended_no_duplicate(): void
    {
        $this->assertSame(
            ['7'],
            RsvpReminderBuckets::withBucketAppended(['7'], RsvpReminderBuckets::BUCKET_7)
        );
    }

    public function test_guest_cast_persists_only_allowed_buckets(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $guest = Guest::factory()->for($event)->create([
            'rsvp_reminders_sent' => ['7', 'oops', '3'],
        ]);

        $guest->refresh();

        $this->assertSame(['7', '3'], $guest->rsvp_reminders_sent);

        $raw = $guest->getRawOriginal('rsvp_reminders_sent');
        $this->assertIsString($raw);
        $this->assertSame('["7","3"]', $raw);
    }

    public function test_guest_cast_empty_normalized_stores_null(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $guest = Guest::factory()->for($event)->create([
            'rsvp_reminders_sent' => ['junk'],
        ]);

        $guest->refresh();

        $this->assertSame([], $guest->rsvp_reminders_sent);
        $this->assertNull($guest->getRawOriginal('rsvp_reminders_sent'));
    }
}
