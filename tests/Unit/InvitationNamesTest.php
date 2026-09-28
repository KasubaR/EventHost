<?php

namespace Tests\Unit;

use App\Support\InvitationNames;
use PHPUnit\Framework\TestCase;

class InvitationNamesTest extends TestCase
{
    public function test_splits_on_for(): void
    {
        $this->assertSame(['Dinner', 'Kasuba'], InvitationNames::split('Dinner FOR Kasuba'));
    }

    public function test_splits_on_ampersand(): void
    {
        $this->assertSame(['Peter Banda', 'Bwanga Chibaye'], InvitationNames::split(' Peter Banda & Bwanga Chibaye '));
    }

    public function test_splits_on_and(): void
    {
        $this->assertSame(['Amara', 'Julian'], InvitationNames::split('Amara And Julian'));
    }

    public function test_for_wins_over_ampersand(): void
    {
        $this->assertSame(['Party', 'Kasuba & Tamara'], InvitationNames::split('Party for Kasuba & Tamara'));
    }

    public function test_single_name_has_no_second_part(): void
    {
        $this->assertSame(['Tamara', ''], InvitationNames::split('Tamara'));
    }

    public function test_empty_side_keeps_the_whole_name(): void
    {
        $this->assertSame(['& Tamara', ''], InvitationNames::split('& Tamara'));
        $this->assertSame(['Kasuba &', ''], InvitationNames::split('Kasuba &'));
    }

    public function test_and_inside_a_word_is_not_a_separator(): void
    {
        $this->assertSame(['Brandon Anderson', ''], InvitationNames::split('Brandon Anderson'));
    }
}
