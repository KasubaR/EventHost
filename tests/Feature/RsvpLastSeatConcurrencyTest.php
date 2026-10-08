<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Exceptions\GuestLimitReachedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\RsvpSubmissionService;
use App\Support\EventAttendance;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-attendance.md Phase 4: several guests submit for the last seat at the same instant, each from its own process
 * and its own database connection. SQLite ignores lockForUpdate(), so this only means something on MySQL (the
 * "MySQL compatibility" workflow runs it, see phpunit.mysql.xml); everywhere else it is skipped. It commits real rows
 * (DatabaseTruncation, not a wrapping transaction), because a child process cannot see another connection's open one.
 */
class RsvpLastSeatConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const CONTENDERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locks need a real server: this runs on MySQL only.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions to race real processes.');
        }
    }

    public function test_only_one_of_many_simultaneous_guests_gets_the_last_seat(): void
    {
        Notification::fake();

        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'allow_plus_one' => false,
            'guest_limit' => 1,
        ]);

        $guests = [];
        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $guests[] = Guest::factory()->for($event)->create(['invitation_token' => "tok_race_{$i}"]);
        }

        $dir = sys_get_temp_dir().'/rsvp-race-'.bin2hex(random_bytes(4));
        mkdir($dir);

        // Nobody may share the parent's socket: every process opens its own connection.
        DB::disconnect();
        $startAt = microtime(true) + 1.0;
        $pids = [];

        foreach ($guests as $i => $guest) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork.');
            }

            if ($pid === 0) {
                // Child: a fresh connection, wait for the shared start time, submit, report, vanish.
                DB::purge();
                while (microtime(true) < $startAt) {
                    usleep(500);
                }

                try {
                    app(RsvpSubmissionService::class)->submit($event, $guest, ['status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
                    $result = 'accepted';
                } catch (\Throwable $e) {
                    $result = $e::class;
                }

                file_put_contents("{$dir}/{$i}", $result);
                // Not exit(): that would run PHPUnit's shutdown handling inside the child.
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge();

        $results = [];
        foreach (array_keys($guests) as $i) {
            $results[] = is_file("{$dir}/{$i}") ? file_get_contents("{$dir}/{$i}") : 'no result';
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);

        $accepted = array_keys(array_filter($results, fn ($r) => $r === 'accepted'));

        $this->assertCount(1, $accepted, 'Exactly one guest gets the last seat. Results: '.implode(', ', $results));
        $this->assertSame(
            self::CONTENDERS - 1,
            count(array_filter($results, fn ($r) => $r === GuestLimitReachedException::class)),
            'Everyone else is told the event is full. Results: '.implode(', ', $results),
        );
        $this->assertSame(1, EventAttendance::heldSeats($event->id));
        $this->assertSame(1, Rsvp::query()->where('event_id', $event->id)->count());
    }
}
