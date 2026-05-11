<?php

namespace Tests;

use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Invitation rendering expects at least one active template.
     */
    protected bool $seed = true;

    protected string $seeder = InvitationTemplateSeeder::class;
}
