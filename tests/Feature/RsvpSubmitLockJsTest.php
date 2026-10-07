<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RsvpSubmitLockJsTest extends TestCase
{
    public function test_rsvp_form_script_swallows_a_second_submit(): void
    {
        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('node is not installed; run `node tests/js/rsvp-submit-lock.cjs` where it is.');
        }

        $result = Process::path(base_path())->run('node tests/js/rsvp-submit-lock.cjs');

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
    }
}
